<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\ServerClone;
use App\Domain\Repository\SyncState;
use App\Domain\Review\ReviewState;
use App\Git\RepositoryLock;
use App\Message\CloneRepositoryJob;
use App\Message\FetchRepositoryJob;
use App\Message\IndexReview;
use App\Message\RecomputeLocations;
use App\Repository\RepositoryRepository;
use App\Repository\ReviewRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class RepositorySyncHandler
{
    /** A first clone of a large repository takes longer than a synchronous caller waits. */
    private const float TIMEOUT_SECONDS = 600.0;

    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly RepositoryLock $repositoryLock,
        private readonly ServerClone $serverClone,
        private readonly ReviewRepository $reviews,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Runs by the code access of the repository when it starts (D111). After a success, the index job and the location
     * recompute of each open review run again, because a code access change ends here.
     */
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

        if (null === $cloning) {
            return;
        }

        $this->serverClone->synced($cloning, $fetched);

        foreach (null === $fetched ? [] : $this->reviews->findOfRepository($cloning->getId(), ReviewState::Open) as $review) {
            $this->bus->dispatch(new IndexReview($review->getId()));
            $this->bus->dispatch(new RecomputeLocations($review->getId()));
        }
    }

    /**
     * Clears the flag once it holds the lock, so a request during the wait reuses this job, and a request during the
     * fetch queues one more. A failed acquisition clears it too.
     */
    #[AsMessageHandler]
    public function handleFetch(FetchRepositoryJob $job): void
    {
        try {
            $lock = $this->repositoryLock->acquire($job->repositoryId);
        } finally {
            $this->repositories->clearFetchRequest($job->repositoryId);
        }

        try {
            $repository = $this->repositories->findFresh($job->repositoryId);
            $fetching = null !== $repository && $this->repositories->startFetch($job->repositoryId) ? $repository : null;
            $fetched = null === $fetching ? null : $this->serverClone->fetch($fetching, false, self::TIMEOUT_SECONDS, collectGarbage: true);
        } finally {
            $lock->release();
        }

        if (null !== $fetching) {
            $this->serverClone->synced($fetching, $fetched);
        }
    }
}
