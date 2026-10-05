<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryChanged;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Git\RepositoryLock;
use App\Git\TenantDataPaths;
use App\Message\CloneRepositoryJob;
use App\Message\DeleteCloneJob;
use App\Message\FetchRepositoryJob;
use App\Message\RecomputeLocations;
use App\Repository\RepositoryRepository;
use App\Repository\ReviewRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The sync state transitions are the conditional updates of {@see RepositoryRepository}.
 */
final class FetchRepository
{
    public const float SYNCHRONOUS_DEADLINE_SECONDS = 60.0;

    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly ReviewRepository $reviews,
        private readonly RepositoryLock $repositoryLock,
        private readonly ServerClone $serverClone,
        private readonly TenantDataPaths $paths,
        private readonly MessageBusInterface $bus,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * R5: queues a fetch, or the clone again after a failed clone. A queued fetch or clone is reused. A request during
     * a running fetch queues one more.
     *
     * @param int $delayMilliseconds Of the fetch job. A request meanwhile reuses it, which debounces fetches.
     *
     * @throws NotFound
     * @throws RepositoryUnavailable With the reason `removed`
     */
    public function request(RepositoryId $id, int $delayMilliseconds = 0): void
    {
        $this->refuseOpenTransaction();
        $repository = $this->repositories->findFresh($id) ?? throw new NotFound();
        $state = $repository->getSyncState();

        if (SyncState::Unavailable === $state) {
            throw new RepositoryUnavailable($id, RepositoryUnavailableReason::Removed);
        }

        if (SyncState::Failed === $state && $this->repositories->restartClone($id)) {
            $this->queueClone($repository);
        }

        if (in_array($state, [SyncState::Idle, SyncState::Fetching], true) && $this->repositories->requestFetch($id)) {
            try {
                $this->bus->dispatch(new FetchRepositoryJob($id), $delayMilliseconds > 0 ? [new DelayStamp($delayMilliseconds)] : []);
            } catch (\Throwable $failure) {
                $this->repositories->clearFetchRequest($id);

                throw $failure;
            }

            $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $id));
        }
    }

    /**
     * For a repository that the caller set to `cloning`. A failed dispatch sets `failed`, so {@see request()} queues it
     * again.
     */
    public function queueClone(Repository $repository): void
    {
        $this->refuseOpenTransaction();
        $id = $repository->getId();

        try {
            $this->bus->dispatch(new CloneRepositoryJob($id));
        } catch (\Throwable $failure) {
            $this->repositories->recordFailure($id, 'The clone job could not be queued.');

            throw $failure;
        } finally {
            $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $id));
        }
    }

    /**
     * Waits for a running fetch, then fetches. During a clone it throws `cloning`. The wait and the fetch share one deadline.
     *
     * @throws NotFound
     * @throws RepositoryUnavailable
     */
    public function fetchNow(RepositoryId $id): void
    {
        $this->refuseOpenTransaction();
        $deadlineUnixSeconds = $this->nowUnixSeconds() + self::SYNCHRONOUS_DEADLINE_SECONDS;
        $this->cloneReady($id);
        $lock = $this->repositoryLock->acquire($id, $deadlineUnixSeconds)
            ?? throw new RepositoryUnavailable($id, RepositoryUnavailableReason::FetchFailed);

        try {
            $repository = $this->cloneReady($id);
            $this->repositories->startFetch($id);

            if (SyncState::Idle === $repository->getSyncState()) {
                $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $id));
            }

            $fetched = $this->serverClone->fetch($repository, false, $deadlineUnixSeconds - $this->nowUnixSeconds());
        } finally {
            $lock->release();
        }

        $this->serverClone->synced($repository, $fetched);

        if (null === $fetched) {
            throw new RepositoryUnavailable($id, RepositoryUnavailableReason::FetchFailed);
        }
    }

    /**
     * @return Repository In the state `idle` or `fetching`, which have a clone
     *
     * @throws NotFound
     * @throws RepositoryUnavailable
     */
    public function cloneReady(RepositoryId $id): Repository
    {
        $repository = $this->repositories->findFresh($id) ?? throw new NotFound();
        $reason = match ($repository->getSyncState()) {
            SyncState::Idle, SyncState::Fetching => null,
            SyncState::Cloning => RepositoryUnavailableReason::Cloning,
            SyncState::Failed => RepositoryUnavailableReason::CloneFailed,
            SyncState::Unavailable => RepositoryUnavailableReason::Removed,
        };

        return null === $reason ? $repository : throw new RepositoryUnavailable($id, $reason);
    }

    /**
     * @throws NotFound
     * @throws RepositoryUnavailable
     */
    public function readyClonePath(RepositoryId $id): string
    {
        return $this->paths->clonePath($this->cloneReady($id)->getTenantId(), $id);
    }

    /**
     * D22: marks the repository removed and queues the deletion of its clone and index. Its reviews stay, and the
     * recompute sets the `outdated` flags of their requests to null.
     */
    public function removeAccess(Repository $repository): void
    {
        $this->refuseOpenTransaction();
        $id = $repository->getId();

        if (!$this->repositories->removeAccess($id)) {
            return;
        }

        $this->bus->dispatch(new DeleteCloneJob($repository->getTenantId(), $id));

        foreach ($this->reviews->findOfRepository($id) as $review) {
            $this->bus->dispatch(new RecomputeLocations($review->getId()));
        }

        $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $id));
    }

    private function nowUnixSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    /**
     * A job dispatched in a transaction can run before the commit, and a RepositoryFetched listener writes through the
     * CommandExecutor, which refuses an open transaction.
     */
    private function refuseOpenTransaction(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Repository syncs run only outside a database transaction.');
        }
    }
}
