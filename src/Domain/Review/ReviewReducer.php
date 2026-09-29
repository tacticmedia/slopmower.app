<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Event\RecordedEvent;
use App\Domain\Event\ReviewClosed;
use App\Domain\Event\ReviewStarted;

final class ReviewReducer
{
    /**
     * Folds any event of the review. Events other than review.started and review.closed leave it unchanged.
     *
     * @param ReviewSnapshot|null $review null before review.started
     *
     * @throws \LogicException for an event of another review or out of lifecycle order
     */
    public static function apply(?ReviewSnapshot $review, RecordedEvent $recorded): ReviewSnapshot
    {
        $event = $recorded->event;
        if (null === $review) {
            return $event instanceof ReviewStarted
                ? ReviewSnapshot::open($recorded->reviewId, $event->commitSha)
                : throw new \LogicException(\sprintf('The first event of a review must be review.started, not %s.', $recorded->type->value));
        }
        if (!$review->reviewId->equals($recorded->reviewId)) {
            throw new \LogicException(\sprintf('Event %s belongs to review %s, not %s.', $recorded->id, $recorded->reviewId, $review->reviewId));
        }

        return match (true) {
            $event instanceof ReviewStarted => throw new \LogicException(\sprintf('Review %s is already started.', $review->reviewId)),
            $event instanceof ReviewClosed => $review->close($recorded->occurredAt, $recorded->actor, $event->note),
            default => $review,
        };
    }
}
