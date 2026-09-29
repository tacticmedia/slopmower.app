<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\DomainError;
use App\Domain\Error\Forbidden;
use App\Domain\Error\ReviewClosed as ReviewClosedError;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\ReviewClosed;
use App\Domain\Event\ReviewStarted;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\ReviewCommand;
use App\Domain\Review\Command\StartReview;

/**
 * The review lifecycle of architecture section 6. Only the user starts and closes reviews.
 */
final class ReviewDecider
{
    /**
     * @param ReviewSnapshot|null $review null for StartReview only
     *
     * @return list<DecidedEvent>
     *
     * @throws DomainError     forbidden for an actor other than a user, review_closed for a second close
     * @throws \LogicException for a review that does not fit the command
     */
    public static function decide(ReviewCommand $command, ?ReviewSnapshot $review, Actor $actor): array
    {
        return match (true) {
            $command instanceof StartReview => self::start($command, $review, $actor),
            $command instanceof CloseReview => self::close($command, $review, $actor),
            default => throw new \LogicException(\sprintf('Unknown review command %s.', $command::class)),
        };
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function start(StartReview $command, ?ReviewSnapshot $review, Actor $actor): array
    {
        self::assertUser($actor, 'start');
        if (null !== $review) {
            throw new \LogicException(\sprintf('Review %s is already started.', $review->reviewId));
        }

        return [DecidedEvent::withoutRequest(new ReviewStarted($command->title, $command->ref, $command->commitSha))];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function close(CloseReview $command, ?ReviewSnapshot $review, Actor $actor): array
    {
        self::assertUser($actor, 'close');
        if (null === $review) {
            throw new \LogicException('Closing needs the review.');
        }
        if ($review->isClosed()) {
            throw new ReviewClosedError($review->reviewId, $review->closedAt);
        }

        return [DecidedEvent::withoutRequest(new ReviewClosed($command->note))];
    }

    private static function assertUser(Actor $actor, string $commandName): void
    {
        if (ActorKind::User !== $actor->kind) {
            throw Forbidden::actorKind($actor->kind, $commandName);
        }
    }
}
