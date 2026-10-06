<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryFetched;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Review\ReviewState;
use App\EventStore\EventsCommitted;
use App\Message\RecomputeLocations;
use App\Repository\ReviewRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues {@see RecomputeLocations}. Both events follow a commit, so the dispatch runs outside a transaction.
 */
final class QueueLocationRecompute
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Step 3 of docs/specs/webhooks-and-live-updates.md section 2.2.
     */
    #[AsEventListener]
    public function onRepositoryFetched(RepositoryFetched $fetched): void
    {
        $previous = $fetched->previousDefaultBranchHead;
        $head = $fetched->defaultBranchHead;

        if (null === $previous ? null === $head : null !== $head && $previous->equals($head)) {
            return;
        }

        foreach ($this->reviews->findOfRepository($fetched->repositoryId, ReviewState::Open) as $review) {
            $this->bus->dispatch(new RecomputeLocations($review->getId()));
        }
    }

    /**
     * A new request gets its flag without a fetch.
     */
    #[AsEventListener]
    public function onEventsCommitted(EventsCommitted $committed): void
    {
        if (array_any($committed->events, static fn (RecordedEvent $recorded): bool => $recorded->event instanceof RequestOpenedEvent)) {
            $this->bus->dispatch(new RecomputeLocations($committed->reviewId));
        }
    }
}
