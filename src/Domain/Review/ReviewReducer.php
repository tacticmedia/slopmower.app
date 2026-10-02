<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Event\RecordedEvent;
use App\Domain\Event\ReviewClosedEvent;
use App\Domain\Event\ReviewStartedEvent;

/**
 * Applies the review events. Other events of the review leave the snapshot unchanged.
 */
final class ReviewReducer
{
    /**
     * @param ReviewSnapshot|null $review Null only for review.started
     */
    public static function apply(?ReviewSnapshot $review, RecordedEvent $recorded): ReviewSnapshot
    {
        $event = $recorded->event;

        if ($event instanceof ReviewStartedEvent) {
            if (null !== $review) {
                throw new \LogicException('The review is started already.');
            }

            return new ReviewSnapshot(
                id: $recorded->reviewId,
                title: $event->title,
                ref: $event->ref,
                commitSha: $event->commitSha,
                startedBy: $recorded->actor,
                createdAt: $recorded->occurredAt,
                closedAt: null,
                closedBy: null,
                closeNote: null,
            );
        }

        if (null === $review || !$review->id->equals($recorded->reviewId)) {
            throw new \LogicException("{$recorded->type->value} needs its started review.");
        }

        if (!$event instanceof ReviewClosedEvent) {
            return $review;
        }

        return new ReviewSnapshot(
            id: $review->id,
            title: $review->title,
            ref: $review->ref,
            commitSha: $review->commitSha,
            startedBy: $review->startedBy,
            createdAt: $review->createdAt,
            closedAt: $recorded->occurredAt,
            closedBy: $recorded->actor,
            closeNote: $event->note,
        );
    }
}
