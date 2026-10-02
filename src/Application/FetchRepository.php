<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryFetched;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositorySource;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Git\BareClone;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\RepositoryLock;
use App\Git\TenantDataPaths;
use App\Message\CloneRepositoryJob;
use App\Message\FetchRepositoryJob;
use App\Repository\RepositoryRepository;
use App\Source\LocalFetchCommand;
use App\Source\LocalRepositoryDiscovery;
use App\Source\SourceUnavailable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Clones and fetches the server clones, one clone or fetch per repository at a time (D24).
 * The sync state transitions are the conditional updates of {@see RepositoryRepository}.
 */
final class FetchRepository
{
    public const float SYNCHRONOUS_DEADLINE_SECONDS = 60.0;

    /** A first clone of a large repository takes longer than a synchronous caller waits. */
    private const float JOB_TIMEOUT_SECONDS = 600.0;

    private const float LOCK_POLL_SECONDS = 0.1;

    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly RepositoryLock $repositoryLock,
        private readonly BareClone $bareClone,
        private readonly GitReader $reader,
        private readonly LocalRepositoryDiscovery $discovery,
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
     * @throws NotFound
     * @throws RepositoryUnavailable With the reason `removed`
     */
    public function request(RepositoryId $id): void
    {
        $this->refuseOpenTransaction();
        $state = ($this->repositories->findFresh($id) ?? throw new NotFound())->getSyncState();

        if (SyncState::Unavailable === $state) {
            throw new RepositoryUnavailable($id, RepositoryUnavailableReason::Removed);
        }

        if (SyncState::Failed === $state && $this->repositories->restartClone($id)) {
            $this->queueClone($id);
        }

        if (in_array($state, [SyncState::Idle, SyncState::Fetching], true) && $this->repositories->requestFetch($id)) {
            try {
                $this->bus->dispatch(new FetchRepositoryJob($id));
            } catch (\Throwable $failure) {
                $this->repositories->clearFetchRequest($id);

                throw $failure;
            }
        }
    }

    /**
     * For a repository in the state `cloning`. A failed dispatch sets `failed`, so {@see request()} queues it again.
     */
    public function queueClone(RepositoryId $id): void
    {
        $this->refuseOpenTransaction();

        try {
            $this->bus->dispatch(new CloneRepositoryJob($id));
        } catch (\Throwable $failure) {
            $this->repositories->recordFailure($id, 'The clone job could not be queued.');

            throw $failure;
        }
    }

    /**
     * Waits for a running clone or fetch, then fetches. The wait and the fetch share one deadline.
     *
     * @throws NotFound
     * @throws RepositoryUnavailable
     */
    public function fetchNow(RepositoryId $id): void
    {
        $this->refuseOpenTransaction();
        $deadlineUnixSeconds = $this->nowUnixSeconds() + self::SYNCHRONOUS_DEADLINE_SECONDS;
        $this->cloneReady($id);
        $lock = $this->repositoryLock->create($id);

        if (!$this->acquire($lock, $deadlineUnixSeconds)) {
            throw new RepositoryUnavailable($id, RepositoryUnavailableReason::FetchFailed);
        }

        try {
            $repository = $this->cloneReady($id);
            $this->repositories->startFetch($id);
            $fetched = $this->fetchLocked($repository, false, $deadlineUnixSeconds - $this->nowUnixSeconds());
        } finally {
            $lock->release();
        }

        if (null === $fetched) {
            throw new RepositoryUnavailable($id, RepositoryUnavailableReason::FetchFailed);
        }

        $this->dispatcher->dispatch($fetched);
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

    #[AsMessageHandler]
    public function handleClone(CloneRepositoryJob $job): void
    {
        $lock = $this->repositoryLock->create($job->repositoryId);
        $this->acquire($lock, null);

        try {
            $repository = $this->repositories->findFresh($job->repositoryId);
            $fetched = SyncState::Cloning === $repository?->getSyncState() ? $this->fetchLocked($repository, true, self::JOB_TIMEOUT_SECONDS) : null;
        } finally {
            $lock->release();
        }

        if (null !== $fetched) {
            $this->dispatcher->dispatch($fetched);
        }
    }

    /**
     * Clears the flag first, so a request from now on queues one more job, and a failure here cannot leave it set.
     */
    #[AsMessageHandler]
    public function handleFetch(FetchRepositoryJob $job): void
    {
        $this->repositories->clearFetchRequest($job->repositoryId);
        $lock = $this->repositoryLock->create($job->repositoryId);
        $this->acquire($lock, null);

        try {
            $repository = $this->repositories->findFresh($job->repositoryId);
            $fetched = null !== $repository && $this->repositories->startFetch($job->repositoryId) ? $this->fetchLocked($repository, false, self::JOB_TIMEOUT_SECONDS) : null;
        } finally {
            $lock->release();
        }

        if (null !== $fetched) {
            $this->dispatcher->dispatch($fetched);
        }
    }

    /**
     * The caller holds the lock and dispatches the event after it releases the lock.
     *
     * @return RepositoryFetched|null Null after a failure, which the row records in `lastError`
     */
    private function fetchLocked(Repository $repository, bool $clone, float $timeoutSeconds): ?RepositoryFetched
    {
        $id = $repository->getId();
        $gitDir = $this->paths->clonePath($repository->getTenantId(), $id);
        $filesystem = new Filesystem();

        try {
            $command = $this->localFetchCommand($repository);

            if ($clone) {
                // A failed earlier attempt may have left a partial clone.
                $filesystem->remove($gitDir);
                $this->bareClone->init($gitDir);
            }

            $this->bareClone->fetch($gitDir, $command->url, timeoutSeconds: $timeoutSeconds, uploadPack: $command->uploadPack);
        } catch (GitFailed|SourceUnavailable|IOExceptionInterface $failure) {
            // Recorded first, so a failing removal cannot leave the row in `cloning`.
            $this->repositories->recordFailure($id, $failure->getMessage());

            if ($clone) {
                $filesystem->remove($gitDir);
            }

            return null;
        }

        $defaultBranch = $this->reader->headBranch($gitDir);

        try {
            $head = null === $defaultBranch ? null : $this->reader->resolveCommit($gitDir, RefName::fromString("refs/heads/{$defaultBranch}"));
        } catch (CommitNotFound|InvalidInput) {
            // Git accepts branch names that RefName refuses, for example names longer than 255 characters.
            $head = null;
        }

        // An unborn branch is not a default branch yet.
        $this->repositories->recordSuccess($id, $this->clock->now(), null === $head ? null : $defaultBranch, $head);

        return new RepositoryFetched($repository->getTenantId(), $id, $repository->getDefaultBranchHead(), $head);
    }

    /**
     * @throws SourceUnavailable
     */
    private function localFetchCommand(Repository $repository): LocalFetchCommand
    {
        if (RepositorySource::Local !== $repository->getSource()) {
            throw new \LogicException('Only local sources can be fetched.');
        }

        $directory = (string) $repository->getLocalDirectory();
        $candidate = $this->discovery->find($directory)
            ?? throw new SourceUnavailable("The directory {$directory} is not a repository under the local repositories directory, or local repositories are off.");

        return LocalFetchCommand::forDirectory($candidate->realPath);
    }

    /**
     * Polls, because Symfony Lock has no acquire timeout and the PostgreSQL store blocks without limit.
     *
     * @param float|null $deadlineUnixSeconds Null for no limit
     */
    private function acquire(LockInterface $lock, ?float $deadlineUnixSeconds): bool
    {
        while (!$lock->acquire()) {
            if (null !== $deadlineUnixSeconds && $this->nowUnixSeconds() >= $deadlineUnixSeconds) {
                return false;
            }

            $this->clock->sleep(self::LOCK_POLL_SECONDS);
        }

        return true;
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
