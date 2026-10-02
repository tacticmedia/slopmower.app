<?php

declare(strict_types=1);

namespace App\Projection;

use App\Domain\Event\RecordedEvent;
use App\Domain\Event\ReviewStartedEvent;
use App\Domain\Review\ReviewReducer;
use App\Repository\ReviewRepository;

/**
 * Keeps the event columns of `review`, `lastEventSeq` included. The start use case creates the row, because the
 * repository is not event data.
 */
final class ReviewProjector
{
    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    public function project(RecordedEvent $recorded): void
    {
        $review = $this->reviews->findFresh($recorded->reviewId) ?? throw new \LogicException("The review {$recorded->reviewId} has no row.");
        $before = $recorded->event instanceof ReviewStartedEvent ? null : $review->toSnapshot();

        $review->project(ReviewReducer::apply($before, $recorded), $recorded->seq);
    }
}
