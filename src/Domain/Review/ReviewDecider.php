<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\ReviewClosedEvent;
use App\Domain\Event\ReviewStartedEvent;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\StartReview;

/**
 * The review lifecycle of architecture section 6.
 */
final class ReviewDecider
{
    /**
     * @param ReviewSnapshot|null $review Null only for StartReview
     *
     * @return list<DecidedEvent>
     *
     * @throws ReviewClosed
     */
    public static function decide(StartReview|CloseReview $command, ?ReviewSnapshot $review, Actor $actor): array
    {
        if (ActorKind::System === $actor->kind) {
            throw new \LogicException('The system issues no review command.');
        }

        if ($command instanceof CloseReview && ActorKind::User !== $actor->kind) {
            throw new \LogicException('Only a user closes a review.');
        }

        if ($command instanceof StartReview) {
            return null === $review
                ? [new DecidedEvent(new ReviewStartedEvent($command->title, $command->ref, $command->commitSha))]
                : throw new \LogicException('The review is started already.');
        }

        if (null === $review) {
            throw new \LogicException('Only a started review is closed.');
        }

        if (null !== $review->closedAt) {
            throw new ReviewClosed($review->id, $review->closedAt);
        }

        return [new DecidedEvent(new ReviewClosedEvent($command->note))];
    }
}
