<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\PullRequestLinkedEvent;
use App\Domain\Event\PullRequestUnlinkedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Event\RequestPlanRequiredEvent;
use App\Domain\Event\RequestPlanWaivedEvent;
use App\Domain\Event\RequestProposalApprovedEvent;
use App\Domain\Event\RequestProposalRejectedEvent;
use App\Domain\Event\RequestProposedEvent;
use App\Domain\Event\RequestReadyForReviewEvent;
use App\Domain\Event\RequestReleasedEvent;
use App\Domain\Event\RequestReopenedEvent;
use App\Domain\Event\RequestSuggestionAcceptedEvent;
use App\Domain\Event\RequestSuggestionDismissedEvent;
use App\Domain\Event\RequestVerifiedEvent;
use App\Domain\Event\RequestWontDoEvent;
use App\Domain\Event\WontDoReason;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\UniqueIds;
use App\Domain\Repository\RepositorySource;
use App\Domain\Request\Command\AcceptSuggestion;
use App\Domain\Request\Command\ApproveProposal;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\DismissSuggestion;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\LinkPullRequest;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\ProposeSolution;
use App\Domain\Request\Command\RejectProposal;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\SetPlanRequirement;
use App\Domain\Request\Command\Suggest;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\UnlinkPullRequest;
use App\Domain\Request\Command\Verify;
use App\Domain\Review\ReviewSnapshot;

/**
 * Checks in the order of docs/specs/README.md section 4: invalid_input, review_closed, invalid_transition, commits_not_available.
 * {@see AllowedActions} decides invalid_transition.
 */
final class RequestDecider
{
    public const int MAX_PENDING_SUGGESTIONS = 500;
    private const int MAX_LINKED_REQUESTS = 50;

    /**
     * @param list<RequestState> $states             The state of each request that the command names
     * @param list<CommitInfo>   $availability       For link_commits: the commit, when the server clone has it
     * @param int                $pendingSuggestions For suggest: the pending suggestions of the review, read in the transaction of the write
     *
     * @return list<DecidedEvent> Empty when the command changes nothing
     *
     * @throws InvalidInput|ReviewClosed|InvalidTransition|CommitsNotAvailable
     */
    public static function decide(RequestCommand $command, array $states, ReviewSnapshot $review, Actor $actor, array $availability = [], int $pendingSuggestions = 0): array
    {
        self::guard($command, $review, $actor);
        $byId = self::index($command, $states);
        $violations = self::violations($command, $byId, $review, $actor, $pendingSuggestions);

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        if (null !== $review->closedAt) {
            throw new ReviewClosed($review->id, $review->closedAt);
        }

        if ($command instanceof Open) {
            return [new DecidedEvent(new RequestOpenedEvent($command->anchor, $command->body, $command->batchId, $command->carriedFrom, $command->requireApproval), RequestId::generate())];
        }

        if ($command instanceof Suggest) {
            return [new DecidedEvent(new RequestOpenedEvent($command->anchor, $command->body, null), RequestId::generate())];
        }

        $action = self::action($command);

        foreach ($command->requestIds() as $requestId) {
            $state = $byId[$requestId->toString()];

            if ($command instanceof Claim && $state->isClaimedBy($actor)) {
                return [];
            }

            $allowedActions = AllowedActions::for($state, $review, $actor);

            if (!in_array($action, $allowedActions, true)) {
                throw new InvalidTransition($state->id, $action, $state->status, $state->claimedBy, $allowedActions, self::closedGateReason($action, $state, $actor));
            }
        }

        if ($command instanceof LinkCommits) {
            return self::linkCommits($command, $byId, $availability);
        }

        if ($command instanceof LinkPullRequest) {
            return self::linkPullRequest($command, $byId);
        }

        $state = $byId[$command->requestIds()[0]->toString()];

        if ($command instanceof MarkReadyForReview) {
            self::assertCommitsAvailable($state);
        }

        if ($command instanceof SetPlanRequirement && $command->requireApproval === $state->requireApproval) {
            return [];
        }

        $event = match (true) {
            $command instanceof Reply => new RequestCommentedEvent(CommentId::generate(), $command->body, $command->question, $command->batchId),
            $command instanceof Claim => new RequestClaimedEvent(),
            $command instanceof Release => new RequestReleasedEvent($command->note),
            $command instanceof UnlinkCommit => new CommitUnlinkedEvent($command->commitSha, $state->id),
            $command instanceof UnlinkPullRequest => new PullRequestUnlinkedEvent(self::unlinkedPullRequest($command, $state), $state->id),
            $command instanceof MarkReadyForReview => new RequestReadyForReviewEvent($command->note),
            $command instanceof MarkWontDo => new RequestWontDoEvent(WontDoReason::fromString($command->reason)),
            $command instanceof Verify => new RequestVerifiedEvent($command->note),
            $command instanceof Reopen => new RequestReopenedEvent($command->note),
            $command instanceof ProposeSolution => new RequestProposedEvent($command->plan, null === $command->diff ? null : ProposedDiff::fromString($command->diff)),
            $command instanceof ApproveProposal => new RequestProposalApprovedEvent($command->proposalEventId, $command->note),
            $command instanceof RejectProposal => new RequestProposalRejectedEvent($command->proposalEventId, $command->note),
            $command instanceof SetPlanRequirement => $command->requireApproval ? new RequestPlanRequiredEvent() : new RequestPlanWaivedEvent(),
            $command instanceof AcceptSuggestion => new RequestSuggestionAcceptedEvent($command->requireApproval ?? $state->requireApproval),
            $command instanceof DismissSuggestion => new RequestSuggestionDismissedEvent($command->note),
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

        if ($command instanceof Suggest && ActorKind::Agent !== $actor->kind) {
            throw new \LogicException('Only an agent suggests a request.');
        }

        $anchor = $command instanceof Open || $command instanceof Suggest ? $command->anchor : null;

        if (null !== $anchor && !$anchor->commitSha->equals($review->commitSha)) {
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
    private static function violations(RequestCommand $command, array $byId, ReviewSnapshot $review, Actor $actor, int $pendingSuggestions): array
    {
        return match (true) {
            $command instanceof Open => self::textViolations('body', $command->body),
            $command instanceof Suggest => [
                ...self::textViolations('body', $command->body),
                ...($pendingSuggestions >= self::MAX_PENDING_SUGGESTIONS
                    ? [['field' => '', 'message' => 'A review has at most 500 pending suggestions. Wait until the user accepts or dismisses some.']]
                    : []),
            ],
            $command instanceof Reply => [
                ...self::textViolations('body', $command->body),
                ...($command->question && ActorKind::User === $actor->kind ? [['field' => 'question', 'message' => 'Only an agent asks a question.']] : []),
            ],
            $command instanceof LinkCommits => self::linkViolations($command->requestIds, $byId, $review),
            $command instanceof LinkPullRequest => [
                ...self::pullRequestUrlViolations($command),
                ...self::linkViolations($command->requestIds, $byId, $review),
            ],
            $command instanceof UnlinkCommit => null === $byId[$command->requestId->toString()]->link($command->commitSha)
                ? [['field' => 'commitSha', 'message' => 'Is not linked to the request.']]
                : [],
            $command instanceof UnlinkPullRequest => self::unlinkPullRequestViolations($command, $byId[$command->requestId->toString()]),
            $command instanceof MarkReadyForReview, $command instanceof Reopen => self::textViolations('note', $command->note),
            $command instanceof MarkWontDo => self::reasonViolations($command->reason),
            $command instanceof ProposeSolution => [...self::textViolations('plan', $command->plan), ...self::diffViolations($command->diff)],
            $command instanceof ApproveProposal => self::proposalViolations($byId[$command->requestId->toString()], $command->proposalEventId),
            $command instanceof RejectProposal => [
                ...self::proposalViolations($byId[$command->requestId->toString()], $command->proposalEventId),
                ...self::textViolations('note', $command->note),
            ],
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
     * @return list<array{field: string, message: string}>
     */
    private static function diffViolations(?string $diff): array
    {
        if (null === $diff) {
            return [];
        }

        try {
            ProposedDiff::fromString($diff);
        } catch (InvalidInput $error) {
            return $error->details()['violations'];
        }

        return [];
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private static function pullRequestUrlViolations(LinkPullRequest $command): array
    {
        try {
            PullRequestUrl::forRepository($command->url, $command->repository);
        } catch (InvalidInput $error) {
            return $error->details()['violations'];
        }

        return [];
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private static function unlinkPullRequestViolations(UnlinkPullRequest $command, RequestState $state): array
    {
        try {
            self::unlinkedPullRequest($command, $state);
        } catch (InvalidInput $error) {
            return $error->details()['violations'];
        }

        return [];
    }

    /**
     * The linked URL that the command names. {@see PullRequestUrl::forRepository()} checks the current name of a GitHub
     * repository, so a linked URL with an earlier name, in any letter case, is taken as it is. The exact URL wins over
     * the number, because a link from before D64 can hold one number under two names.
     *
     * @throws InvalidInput
     */
    private static function unlinkedPullRequest(UnlinkPullRequest $command, RequestState $state): PullRequestUrl
    {
        $source = $command->repository->source();

        try {
            $url = PullRequestUrl::forRepository($command->url, $command->repository);
        } catch (InvalidInput $invalid) {
            $asLinked = RepositorySource::Github === $source
                ? array_find($state->pullRequestUrls, static fn (PullRequestUrl $linked): bool => 0 === strcasecmp($linked->toString(), $command->url))
                : null;
            $url = $asLinked ?? throw $invalid;
        }

        if ($state->hasPullRequest($url)) {
            return $url;
        }

        return $state->linkedPullRequest($url, $source) ?? throw InvalidInput::field('url', 'Is not linked to the request.');
    }

    /**
     * A decision on a proposal that a newer one replaced. Without a pending proposal, {@see AllowedActions} refuses.
     *
     * @return list<array{field: string, message: string}>
     */
    private static function proposalViolations(RequestState $state, EventId $proposalEventId): array
    {
        $proposal = $state->proposal;

        return null !== $proposal && $proposal->isPending() && !$proposal->eventId->equals($proposalEventId)
            ? [['field' => 'proposalEventId', 'message' => 'Is not the current proposal.']]
            : [];
    }

    /**
     * @param list<RequestId>             $requestIds
     * @param array<string, RequestState> $byId
     *
     * @return list<array{field: string, message: string}>
     */
    private static function linkViolations(array $requestIds, array $byId, ReviewSnapshot $review): array
    {
        $violations = UniqueIds::violations($requestIds, 'requestIds');

        if (count($requestIds) > self::MAX_LINKED_REQUESTS) {
            $violations[] = ['field' => 'requestIds', 'message' => 'Must hold at most 50 IDs.'];
        }

        foreach ($requestIds as $index => $requestId) {
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
            $command instanceof LinkPullRequest => Action::LinkPullRequest,
            $command instanceof UnlinkPullRequest => Action::UnlinkPullRequest,
            $command instanceof MarkReadyForReview => Action::MarkReadyForReview,
            $command instanceof MarkWontDo => Action::MarkWontDo,
            $command instanceof Verify => Action::Verify,
            $command instanceof Reopen => Action::Reopen,
            $command instanceof ProposeSolution => Action::ProposeSolution,
            $command instanceof ApproveProposal => Action::ApproveProposal,
            $command instanceof RejectProposal => Action::RejectProposal,
            $command instanceof SetPlanRequirement => Action::SetPlanRequirement,
            $command instanceof AcceptSuggestion => Action::AcceptSuggestion,
            $command instanceof DismissSuggestion => Action::DismissSuggestion,
            default => throw new \LogicException('No action for '.$command::class.'.'),
        };
    }

    /**
     * Ends the invalid_transition message when a pending suggestion or the closed proposal gate, not the status, refuses
     * the action.
     */
    private static function closedGateReason(Action $action, RequestState $state, Actor $actor): ?string
    {
        $refusedBySuggestion = $state->suggestionPending() && match ($action) {
            Action::Claim => ActorKind::Agent === $actor->kind,
            Action::MarkWontDo => true,
            Action::SetPlanRequirement => ActorKind::User === $actor->kind,
            default => false,
        };

        if ($refusedBySuggestion) {
            return 'the suggestion waits for the user to accept or dismiss it';
        }

        if (!in_array($action, [Action::LinkCommits, Action::MarkReadyForReview, Action::LinkPullRequest], true) || !$state->isClaimedBy($actor) || $state->proposalGateOpen()) {
            return null;
        }

        return match ($state->proposal?->state) {
            ProposalState::Pending => "the proposal waits for the user's decision",
            ProposalState::Rejected => 'the user requested changes, so propose again',
            null => 'the request asks for a plan first, so propose a solution',
            ProposalState::Approved => throw new \LogicException('An approved proposal opens the gate.'),
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
     * @param array<string, RequestState> $byId
     *
     * @return list<DecidedEvent>
     */
    private static function linkPullRequest(LinkPullRequest $command, array $byId): array
    {
        $url = PullRequestUrl::forRepository($command->url, $command->repository);
        $source = $command->repository->source();
        $newlyLinked = array_values(array_filter(
            $command->requestIds,
            static fn (RequestId $requestId): bool => null === $byId[$requestId->toString()]->linkedPullRequest($url, $source),
        ));

        return [] === $newlyLinked ? [] : [new DecidedEvent(new PullRequestLinkedEvent($url, $newlyLinked, $command->note))];
    }

    private static function assertCommitsAvailable(RequestState $state): void
    {
        $pendingCommits = [];

        foreach ($state->links as $link) {
            if (CommitLinkState::Pending === $link->state) {
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
