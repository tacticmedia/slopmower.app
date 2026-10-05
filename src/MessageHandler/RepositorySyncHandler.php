<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\ServerClone;
use App\Domain\Repository\SyncState;
use App\Git\RepositoryLock;
use App\Message\CloneRepositoryJob;
use App\Message\FetchRepositoryJob;
use App\Repository\RepositoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RepositorySyncHandler
{
    /** A first clone of a large repository takes longer than a synchronous caller waits. */
    private const float TIMEOUT_SECONDS = 600.0;

    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly RepositoryLock $repositoryLock,
        private readonly ServerClone $serverClone,
    ) {
    }

    #[AsMessageHandler]
    public function handleClone(CloneRepositoryJob $job): void
    {
        $lock = $this->repositoryLock->acquire($job->repositoryId);

        try {
            $repository = $this->repositories->findFresh($job->repositoryId);
            $cloning = SyncState::Cloning === $repository?->getSyncState() ? $repository : null;
            $fetched = null === $cloning ? null : $this->serverClone->fetch($cloning, true, self::TIMEOUT_SECONDS);
        } finally {
            $lock->release();
        }

        if (null !== $cloning) {
            $this->serverClone->synced($cloning, $fetched);
        }
    }

    /**
     * Clears the flag first, so a request from now on queues one more job, and a failure here cannot leave it set.
     */
    #[AsMessageHandler]
    public function handleFetch(FetchRepositoryJob $job): void
    {
        $this->repositories->clearFetchRequest($job->repositoryId);
        $lock = $this->repositoryLock->acquire($job->repositoryId);

        try {
            $repository = $this->repositories->findFresh($job->repositoryId);
            $fetching = null !== $repository && $this->repositories->startFetch($job->repositoryId) ? $repository : null;
            $fetched = null === $fetching ? null : $this->serverClone->fetch($fetching, false, self::TIMEOUT_SECONDS);
        } finally {
            $lock->release();
        }

        if (null !== $fetching) {
            $this->serverClone->synced($fetching, $fetched);
        }
    }
}
