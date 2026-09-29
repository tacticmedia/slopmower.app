<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\DomainError;
use App\Domain\Error\Forbidden;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\CommitLinked;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestClaimed;
use App\Domain\Event\RequestCommented;
use App\Domain\Event\RequestOpened;
use App\Domain\Event\RequestReadyForReview;
use App\Domain\Event\RequestReleased;
use App\Domain\Event\RequestReopened;
use App\Domain\Event\RequestVerified;
use App\Domain\Event\RequestWontDo;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\Verify;
use App\Domain\Review\ReviewSnapshot;

/**
 * The request commands of architecture section 6. The checks run in the order of docs/specs/README.md section 4:
 * forbidden, invalid_input, review_closed, invalid_transition, commits_not_available.
 * The transition check is {@see AllowedActions::allows()}, so the decider and the allowed actions agree.
 */
final class RequestDecider
{
    /**
     * @param list<RequestState> $states       the state of each request that the command names: none for Open
     * @param CommitAvailability $availability the commits in the server clone, read by LinkCommits and MarkReadyForReview
     *
     * @return list<DecidedEvent> the events to append, in order; empty when the command changes nothing
     *
     * @throws DomainError     when the command is refused
     * @throws \LogicException when the states do not match the command or the review
     */
    public static function decide(
        RequestCommand $command,
        array $states,
        ReviewSnapshot $review,
        Actor $actor,
        CommitAvailability $availability = new CommitAvailability(),
    ): array {
        return match (true) {
            $command instanceof Open => self::open($command, $states, $review, $actor),
            $command instanceof Claim => self::claim(self::stateOf($command->requestId, $states, $review), $review, $actor),
            $command instanceof Release => self::release($command, self::stateOf($command->requestId, $states, $review), $review, $actor),
            $command instanceof Reply => self::reply($command, self::stateOf($command->requestId, $states, $review), $review, $actor),
            $command instanceof LinkCommits => self::linkCommits($command, $states, $review, $actor, $availability),
            $command instanceof UnlinkCommit => self::unlinkCommit($command, self::stateOf($command->requestId, $states, $review), $review, $actor),
            $command instanceof MarkReadyForReview => self::markReadyForReview($command, self::stateOf($command->requestId, $states, $review), $review, $actor, $availability),
            $command instanceof MarkWontDo => self::markWontDo($command, self::stateOf($command->requestId, $states, $review), $review, $actor),
            $command instanceof Verify => self::verify($command, self::stateOf($command->requestId, $states, $review), $review, $actor),
            $command instanceof Reopen => self::reopen($command, self::stateOf($command->requestId, $states, $review), $review, $actor),
            default => throw new \LogicException(\sprintf('Unknown request command %s.', $command::class)),
        };
    }

    /**
     * @param list<RequestState> $states
     *
     * @return list<DecidedEvent>
     */
    private static function open(Open $command, array $states, ReviewSnapshot $review, Actor $actor): array
    {
        if ([] !== $states) {
            throw new \LogicException('Opening a request needs no request states.');
        }
        if (ActorKind::User !== $actor->kind) {
            throw Forbidden::actorKind($actor->kind, 'open');
        }
        if (!$command->anchor->commitSha->equals($review->commitSha)) {
            throw InvalidInput::field('anchor.commitSha', 'must be the review commit');
        }
        self::assertReviewOpen($review);

        return [DecidedEvent::forRequest($command->requestId, new RequestOpened($command->anchor, $command->body, $command->batchId))];
    }

    /**
     * A repeated claim by the holder is not in the allowed actions, and it changes nothing.
     *
     * @return list<DecidedEvent>
     */
    private static function claim(RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        self::assertReviewOpen($review);
        if ($state->isClaimedBy($actor)) {
            return [];
        }
        self::assertAllowed(Action::Claim, $state, $review, $actor);

        return [DecidedEvent::forRequest($state->requestId, new RequestClaimed())];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function release(Release $command, RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        self::assertReviewOpen($review);
        self::assertAllowed(Action::Release, $state, $review, $actor);

        return [DecidedEvent::forRequest($state->requestId, new RequestReleased($command->note))];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function reply(Reply $command, RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        if ($command->question && ActorKind::User === $actor->kind) {
            throw InvalidInput::field('question', 'must be false for a user, because only agents ask questions');
        }
        self::assertReviewOpen($review);
        self::assertAllowed(Action::Reply, $state, $review, $actor);

        return [DecidedEvent::forRequest($state->requestId, new RequestCommented($command->commentId, $command->body, $command->question, $command->batchId))];
    }

    /**
     * @param list<RequestState> $states
     *
     * @return list<DecidedEvent> one commit.linked for the newly linked requests, or none
     */
    private static function linkCommits(LinkCommits $command, array $states, ReviewSnapshot $review, Actor $actor, CommitAvailability $availability): array
    {
        $namedStates = self::statesInCommandOrder($command->requestIds, $states);
        foreach ($namedStates as $state) {
            if (!$state->reviewId->equals($review->reviewId)) {
                throw InvalidInput::field('requestIds', 'must name requests of one review');
            }
        }
        self::assertReviewOpen($review);
        foreach ($namedStates as $state) {
            self::assertAllowed(Action::LinkCommits, $state, $review, $actor);
        }

        $newlyLinkedRequestIds = [];
        foreach ($namedStates as $state) {
            if (null === $state->linkOf($command->commitSha)) {
                $newlyLinkedRequestIds[] = $state->requestId;
            }
        }
        if ([] === $newlyLinkedRequestIds) {
            return [];
        }
        $availableCommit = $availability->find($command->commitSha);

        return [DecidedEvent::withoutRequest(null === $availableCommit
            ? CommitLinked::pending($command->commitSha, $newlyLinkedRequestIds, $command->note)
            : CommitLinked::available($command->commitSha, $newlyLinkedRequestIds, $command->note, $availableCommit->subject, $availableCommit->committedAt))];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function unlinkCommit(UnlinkCommit $command, RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        if (null === $state->linkOf($command->commitSha)) {
            throw InvalidInput::field('commitSha', 'must be a commit linked to the request');
        }
        self::assertReviewOpen($review);
        self::assertAllowed(Action::UnlinkCommit, $state, $review, $actor);

        return [DecidedEvent::withoutRequest(new CommitUnlinked($command->commitSha, $state->requestId))];
    }

    /**
     * A pending link counts as available when the availability lists its commit.
     *
     * @return list<DecidedEvent>
     */
    private static function markReadyForReview(MarkReadyForReview $command, RequestState $state, ReviewSnapshot $review, Actor $actor, CommitAvailability $availability): array
    {
        self::assertReviewOpen($review);
        self::assertAllowed(Action::MarkReadyForReview, $state, $review, $actor);
        $pendingCommits = [];
        foreach ($state->links as $link) {
            if (CommitLinkState::Pending === $link->state && !$availability->contains($link->commitSha)) {
                $pendingCommits[] = $link->commitSha;
            }
        }
        if ([] !== $pendingCommits) {
            throw new CommitsNotAvailable($pendingCommits);
        }

        return [DecidedEvent::forRequest($state->requestId, new RequestReadyForReview($command->note))];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function markWontDo(MarkWontDo $command, RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        self::assertReviewOpen($review);
        self::assertAllowed(Action::MarkWontDo, $state, $review, $actor);

        return [DecidedEvent::forRequest($state->requestId, new RequestWontDo($command->reason))];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function verify(Verify $command, RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        self::assertReviewOpen($review);
        self::assertAllowed(Action::Verify, $state, $review, $actor);

        return [DecidedEvent::forRequest($state->requestId, new RequestVerified($command->note))];
    }

    /**
     * @return list<DecidedEvent>
     */
    private static function reopen(Reopen $command, RequestState $state, ReviewSnapshot $review, Actor $actor): array
    {
        self::assertReviewOpen($review);
        self::assertAllowed(Action::Reopen, $state, $review, $actor);

        return [DecidedEvent::forRequest($state->requestId, new RequestReopened($command->note))];
    }

    /**
     * @param list<RequestState> $states
     */
    private static function stateOf(RequestId $requestId, array $states, ReviewSnapshot $review): RequestState
    {
        if (1 !== \count($states) || !$states[0]->requestId->equals($requestId)) {
            throw new \LogicException(\sprintf('The command needs exactly the state of request %s.', $requestId));
        }
        if (!$states[0]->reviewId->equals($review->reviewId)) {
            throw new \LogicException(\sprintf('Request %s does not belong to review %s.', $requestId, $review->reviewId));
        }

        return $states[0];
    }

    /**
     * @param list<RequestId>    $requestIds
     * @param list<RequestState> $states
     *
     * @return list<RequestState>
     */
    private static function statesInCommandOrder(array $requestIds, array $states): array
    {
        $statesById = [];
        foreach ($states as $state) {
            $statesById[$state->requestId->toString()] = $state;
        }
        if (\count($statesById) !== \count($states) || \count($statesById) !== \count($requestIds)) {
            throw new \LogicException('The command needs exactly one state per named request.');
        }

        return array_map(
            static fn (RequestId $requestId): RequestState => $statesById[$requestId->toString()]
                ?? throw new \LogicException(\sprintf('The state of request %s is missing.', $requestId)),
            $requestIds,
        );
    }

    private static function assertReviewOpen(ReviewSnapshot $review): void
    {
        if ($review->isClosed()) {
            throw new ReviewClosed($review->reviewId, $review->closedAt);
        }
    }

    private static function assertAllowed(Action $action, RequestState $state, ReviewSnapshot $review, Actor $actor): void
    {
        if (!AllowedActions::allows($action, $state, $actor)) {
            throw new InvalidTransition($state->requestId, $action, $state->status, $state->claimedBy, AllowedActions::forCaller($state, $review, $actor));
        }
    }
}
