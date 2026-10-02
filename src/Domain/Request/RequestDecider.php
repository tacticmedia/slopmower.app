<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Event\RequestReadyForReviewEvent;
use App\Domain\Event\RequestReleasedEvent;
use App\Domain\Event\RequestReopenedEvent;
use App\Domain\Event\RequestVerifiedEvent;
use App\Domain\Event\RequestWontDoEvent;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\UniqueIds;
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
 * Checks in the order of docs/specs/README.md section 4: invalid_input, review_closed, invalid_transition, commits_not_available.
 * {@see AllowedActions} decides invalid_transition.
 */
final class RequestDecider
{
    private const int MAX_LINKED_REQUESTS = 50;

    /**
     * @param list<RequestState> $states       The state of each request that the command names
     * @param list<CommitInfo>   $availability The commits that the application found in the server clone
     *
     * @return list<DecidedEvent> Empty when the command changes nothing
     *
     * @throws InvalidInput|ReviewClosed|InvalidTransition|CommitsNotAvailable
     */
    public static function decide(RequestCommand $command, array $states, ReviewSnapshot $review, Actor $actor, array $availability = []): array
    {
        self::guard($command, $review, $actor);
        $byId = self::index($command, $states);
        $violations = self::violations($command, $byId, $review, $actor);

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        if (null !== $review->closedAt) {
            throw new ReviewClosed($review->id, $review->closedAt);
        }

        if ($command instanceof Open) {
            return [new DecidedEvent(new RequestOpenedEvent($command->anchor, $command->body, $command->batchId), RequestId::generate())];
        }

        $action = self::action($command);

        foreach ($command->requestIds() as $requestId) {
            $state = $byId[$requestId->toString()];

            if ($command instanceof Claim && $state->isClaimedBy($actor)) {
                return [];
            }

            $allowedActions = AllowedActions::for($state, $review, $actor);

            if (!in_array($action, $allowedActions, true)) {
                throw new InvalidTransition($state->id, $action, $state->status, $state->claimedBy, $allowedActions);
            }
        }

        if ($command instanceof LinkCommits) {
            return self::linkCommits($command, $byId, $availability);
        }

        $state = $byId[$command->requestIds()[0]->toString()];

        if ($command instanceof MarkReadyForReview) {
            self::assertCommitsAvailable($state, $availability);
        }

        $event = match (true) {
            $command instanceof Reply => new RequestCommentedEvent(CommentId::generate(), $command->body, $command->question, $command->batchId),
            $command instanceof Claim => new RequestClaimedEvent(),
            $command instanceof Release => new RequestReleasedEvent($command->note),
            $command instanceof UnlinkCommit => new CommitUnlinkedEvent($command->commitSha, $state->id),
            $command instanceof MarkReadyForReview => new RequestReadyForReviewEvent($command->note),
            $command instanceof MarkWontDo => new RequestWontDoEvent(WontDoReason::fromString($command->reason)),
            $command instanceof Verify => new RequestVerifiedEvent($command->note),
            $command instanceof Reopen => new RequestReopenedEvent($command->note),
            default => throw new \LogicException('No event for '.$command::class.'.'),
        };

        return [new DecidedEvent($event, $event::type()->isRequestEvent() ? $state->id : null)];
    }

    private static function guard(RequestCommand $command, ReviewSnapshot $review, Actor $actor): void
    {
        if (ActorKind::System === $actor->kind) {
            throw new \LogicException('The system issues no request command.');
        }

        if ($command instanceof Open && ActorKind::User !== $actor->kind) {
            throw new \LogicException('Only a user opens a request.');
        }

        if ($command instanceof Open && !$command->anchor->commitSha->equals($review->commitSha)) {
            throw new \LogicException('The anchor is not at the review commit.');
        }
    }

    /**
     * @param list<RequestState> $states
     *
     * @return array<string, RequestState>
     */
    private static function index(RequestCommand $command, array $states): array
    {
        $byId = [];

        foreach ($states as $state) {
            $byId[$state->id->toString()] = $state;
        }

        foreach ($command->requestIds() as $requestId) {
            if (!isset($byId[$requestId->toString()])) {
                throw new \LogicException("The state of the request {$requestId} is missing.");
            }
        }

        return $byId;
    }

    /**
     * @param array<string, RequestState> $byId
     *
     * @return list<array{field: string, message: string}>
     */
    private static function violations(RequestCommand $command, array $byId, ReviewSnapshot $review, Actor $actor): array
    {
        return match (true) {
            $command instanceof Open => self::textViolations('body', $command->body),
            $command instanceof Reply => [
                ...self::textViolations('body', $command->body),
                ...($command->question && ActorKind::User === $actor->kind ? [['field' => 'question', 'message' => 'Only an agent asks a question.']] : []),
            ],
            $command instanceof LinkCommits => self::linkViolations($command, $byId, $review),
            $command instanceof UnlinkCommit => null === $byId[$command->requestId->toString()]->link($command->commitSha)
                ? [['field' => 'commitSha', 'message' => 'Is not linked to the request.']]
                : [],
            $command instanceof MarkReadyForReview, $command instanceof Reopen => self::textViolations('note', $command->note),
            $command instanceof MarkWontDo => self::reasonViolations($command->reason),
            default => [],
        };
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private static function textViolations(string $field, string $text): array
    {
        return '' === trim($text) ? [['field' => $field, 'message' => 'Must contain a non-whitespace character.']] : [];
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private static function reasonViolations(string $reason): array
    {
        try {
            WontDoReason::fromString($reason);
        } catch (InvalidInput $error) {
            return $error->details()['violations'];
        }

        return [];
    }

    /**
     * @param array<string, RequestState> $byId
     *
     * @return list<array{field: string, message: string}>
     */
    private static function linkViolations(LinkCommits $command, array $byId, ReviewSnapshot $review): array
    {
        $violations = UniqueIds::violations($command->requestIds, 'requestIds');

        if (count($command->requestIds) > self::MAX_LINKED_REQUESTS) {
            $violations[] = ['field' => 'requestIds', 'message' => 'Must hold at most 50 IDs.'];
        }

        foreach ($command->requestIds as $index => $requestId) {
            if (!$byId[$requestId->toString()]->reviewId->equals($review->id)) {
                $violations[] = ['field' => "requestIds.{$index}", 'message' => 'Must belong to the review of the other requests.'];
            }
        }

        return $violations;
    }

    private static function action(RequestCommand $command): Action
    {
        return match (true) {
            $command instanceof Reply => Action::Reply,
            $command instanceof Claim => Action::Claim,
            $command instanceof Release => Action::Release,
            $command instanceof LinkCommits => Action::LinkCommits,
            $command instanceof UnlinkCommit => Action::UnlinkCommit,
            $command instanceof MarkReadyForReview => Action::MarkReadyForReview,
            $command instanceof MarkWontDo => Action::MarkWontDo,
            $command instanceof Verify => Action::Verify,
            $command instanceof Reopen => Action::Reopen,
            default => throw new \LogicException('No action for '.$command::class.'.'),
        };
    }

    /**
     * @param array<string, RequestState> $byId
     * @param list<CommitInfo>            $availability
     *
     * @return list<DecidedEvent>
     */
    private static function linkCommits(LinkCommits $command, array $byId, array $availability): array
    {
        $newlyLinked = array_values(array_filter(
            $command->requestIds,
            static fn (RequestId $requestId): bool => null === $byId[$requestId->toString()]->link($command->commitSha),
        ));

        if ([] === $newlyLinked) {
            return [];
        }

        $commit = self::find($availability, $command->commitSha);

        return [new DecidedEvent(new CommitLinkedEvent($command->commitSha, $newlyLinked, $command->note, null !== $commit, $commit?->subject, $commit?->committedAt))];
    }

    /**
     * @param list<CommitInfo> $availability
     */
    private static function assertCommitsAvailable(RequestState $state, array $availability): void
    {
        $pendingCommits = [];

        foreach ($state->links as $link) {
            if (CommitLinkState::Pending === $link->state && null === self::find($availability, $link->commitSha)) {
                $pendingCommits[] = $link->commitSha;
            }
        }

        if ([] !== $pendingCommits) {
            throw new CommitsNotAvailable($pendingCommits);
        }
    }

    /**
     * @param list<CommitInfo> $availability
     */
    private static function find(array $availability, CommitSha $commitSha): ?CommitInfo
    {
        foreach ($availability as $commit) {
            if ($commit->commitSha->equals($commitSha)) {
                return $commit;
            }
        }

        return null;
    }
}
