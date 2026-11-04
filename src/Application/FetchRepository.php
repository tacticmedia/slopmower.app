<?php

declare(strict_types=1);

namespace App\Application;

use App\Anchoring\LocationCacheSql;
use App\Application\Event\RepositoryChanged;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\CodeAccess;
use App\Domain\Repository\RepositorySource;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Git\CodeReader;
use App\Git\RepositoryLock;
use App\Git\TenantDataPaths;
use App\Index\IndexSql;
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

    /** D93: a long fetch of another caller must not hold a web thread. */
    public const float SYNCHRONOUS_LOCK_WAIT_SECONDS = 5.0;

    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly ReviewRepository $reviews,
        private readonly RepositoryLock $repositoryLock,
        private readonly ServerClone $serverClone,
        private readonly RateLimits $rateLimits,
        private readonly TenantDataPaths $paths,
        private readonly CodeReaders $readers,
        private readonly IndexSql $indexSql,
        private readonly LocationCacheSql $locationCache,
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
     * R10 (D111): a new code access queues the clone job, which deletes the clone of a change away from Server copy and
     * clones after a change to Server copy. The same value changes nothing. Entering Browser only deletes the symbols,
     * the parsed blobs, and the location cache, and leaving it deletes its `unverified` locations (D114).
     *
     * @throws InvalidInput          Pass-through or Browser only for a local repository
     * @throws RepositoryUnavailable With the reason `removed`
     */
    public function changeCodeAccess(Repository $repository, CodeAccess $codeAccess): void
    {
        $this->refuseOpenTransaction();

        if (RepositorySource::Local === $repository->getSource() && CodeAccess::Clone !== $codeAccess) {
            throw InvalidInput::field('codeAccess', 'A local repository is always clone.');
        }

        $current = $this->repositories->findFresh($repository->getId());
        $previous = $current?->getCodeAccess();

        if (SyncState::Unavailable === $current?->getSyncState()) {
            throw new RepositoryUnavailable($repository->getId(), RepositoryUnavailableReason::Removed);
        }

        if (!$this->repositories->changeCodeAccess($repository->getId(), $codeAccess)) {
            return;
        }

        if (CodeAccess::Browser === $codeAccess) {
            $this->indexSql->deleteParsedBlobs($repository->getTenantId(), $repository->getId());
        }

        if (CodeAccess::Browser === $codeAccess || CodeAccess::Browser === $previous) {
            $this->locationCache->deleteOfRepository($repository->getTenantId(), $repository->getId());
        }

        $this->queueClone($repository);
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
     * Waits at most {@see SYNCHRONOUS_LOCK_WAIT_SECONDS} for a running fetch, then fetches within
     * {@see SYNCHRONOUS_DEADLINE_SECONDS}. During a clone it throws `cloning`.
     *
     * @throws NotFound
     * @throws RepositoryUnavailable
     * @throws RateLimited           The `sync_fetch` limit of the repository
     */
    public function fetchNow(RepositoryId $id): void
    {
        $this->refuseOpenTransaction();
        $this->cloneReady($id);
        $this->rateLimits->consumeSyncFetch($id);
        $lock = $this->repositoryLock->acquire($id, $this->nowUnixSeconds() + self::SYNCHRONOUS_LOCK_WAIT_SECONDS)
            ?? throw new RepositoryUnavailable($id, RepositoryUnavailableReason::FetchFailed);

        try {
            $repository = $this->cloneReady($id);
            $this->repositories->startFetch($id);

            if (SyncState::Idle === $repository->getSyncState()) {
                $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $id));
            }

            $fetched = $this->serverClone->fetch($repository, false, self::SYNCHRONOUS_DEADLINE_SECONDS);
        } finally {
            $lock->release();
        }

        $this->serverClone->synced($repository, $fetched);

        if (null === $fetched) {
            throw new RepositoryUnavailable($id, RepositoryUnavailableReason::FetchFailed);
        }
    }

    /**
     * @return Repository In the state `idle` or `fetching`, which have a clone or, for Pass-through, a passed head sync
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
    public function readyReader(RepositoryId $id): CodeReader
    {
        return $this->readers->of($this->cloneReady($id));
    }

    /**
     * D22: marks the repository removed and queues the deletion of its clone and index. Its reviews stay, and the
     * recompute sets the `outdated` flags of their requests to null. A repeated call dispatches again, so a failed
     * dispatch does not leave the clone. The jobs are idempotent.
     */
    public function removeAccess(Repository $repository): void
    {
        $this->refuseOpenTransaction();
        $id = $repository->getId();
        $this->repositories->removeAccess($id);
        $this->bus->dispatch(new DeleteCloneJob($repository->getTenantId(), $id));

        foreach ($this->reviews->findOfRepository($id) as $review) {
            $this->bus->dispatch(new RecomputeLocations($review->getId()));
        }

        $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $id));
    }

    /**
     * For a repository whose access an earlier call removed: queues the deletion of a clone that a failed dispatch left.
     */
    public function deleteLeftClone(Repository $repository): void
    {
        $this->refuseOpenTransaction();

        if (is_dir($this->paths->clonePath($repository->getTenantId(), $repository->getId()))) {
            $this->bus->dispatch(new DeleteCloneJob($repository->getTenantId(), $repository->getId()));
        }
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
