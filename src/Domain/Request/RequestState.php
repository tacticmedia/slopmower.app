<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\RepositorySource;

/**
 * One request as {@see RequestReducer} folds it from the request's thread. {@see AwaitingPolicy} derives `awaiting`.
 */
final readonly class RequestState
{
    /**
     * @param list<LinkedCommit>   $links           In link order
     * @param list<PullRequestUrl> $pullRequestUrls In link order
     */
    public function __construct(
        public RequestId $id,
        public ReviewId $reviewId,
        public ?Anchor $anchor,
        public string $body,
        public Actor $author,
        public \DateTimeImmutable $createdAt,
        public RequestStatus $status,
        public ?RequestStatus $statusBeforeClaim,
        public ?Actor $claimedBy,
        public ?\DateTimeImmutable $claimedAt,
        public ?ActorKind $wontDoBy,
        public ?Resolution $resolution,
        public array $links,
        public array $pullRequestUrls,
        public ?int $lastUserCommentSeq,
        public ?int $lastAgentEventSeq,
        public bool $lastAgentEventIsQuestion,
        public int $lastEventSeq,
        public \DateTimeImmutable $updatedAt,
        public ?CarriedFrom $carriedFrom = null,
        public bool $requireApproval = false,
        public ?Proposal $proposal = null,
        public ?SuggestionState $suggestion = null,
        public ?string $title = null,
    ) {
        $this->assertConsistent();
    }

    /**
     * @param array{status?: RequestStatus, statusBeforeClaim?: ?RequestStatus, claimedBy?: ?Actor, claimedAt?: ?\DateTimeImmutable, wontDoBy?: ?ActorKind, resolution?: ?Resolution, links?: list<LinkedCommit>, pullRequestUrls?: list<PullRequestUrl>, lastUserCommentSeq?: ?int, lastAgentEventSeq?: ?int, lastAgentEventIsQuestion?: bool, lastEventSeq?: int, updatedAt?: \DateTimeImmutable, requireApproval?: bool, proposal?: ?Proposal, suggestion?: ?SuggestionState, title?: string} $changes
     */
    public function with(array $changes): self
    {
        $state = clone ($this, $changes);
        $state->assertConsistent();

        return $state;
    }

    public function closed(): bool
    {
        return $this->status->isClosed($this->wontDoBy);
    }

    public function isClaimedBy(Actor $actor): bool
    {
        return null !== $this->claimedBy && $this->claimedBy->equals($actor);
    }

    public function link(CommitSha $commitSha): ?LinkedCommit
    {
        foreach ($this->links as $link) {
            if ($link->commitSha->equals($commitSha)) {
                return $link;
            }
        }

        return null;
    }

    public function hasPullRequest(PullRequestUrl $url): bool
    {
        return array_any($this->pullRequestUrls, static fn (PullRequestUrl $linked): bool => $linked->equals($url));
    }

    /**
     * The linked URL of the pull request that `$url` names, see {@see PullRequestUrl::namesSamePullRequest()}.
     */
    public function linkedPullRequest(PullRequestUrl $url, RepositorySource $source): ?PullRequestUrl
    {
        return array_find($this->pullRequestUrls, static fn (PullRequestUrl $linked): bool => $linked->namesSamePullRequest($url, $source));
    }

    /**
     * Gates the holder's link_commits, mark_ready_for_review, and link_pull_request in {@see AllowedActions}.
     */
    public function proposalGateOpen(): bool
    {
        return null === $this->proposal ? !$this->requireApproval : ProposalState::Approved === $this->proposal->state;
    }

    public function suggestionPending(): bool
    {
        return SuggestionState::Pending === $this->suggestion;
    }

    private function assertConsistent(): void
    {
        $inProgress = RequestStatus::InProgress === $this->status;

        if ($inProgress !== (null !== $this->claimedBy) || $inProgress !== (null !== $this->claimedAt) || $inProgress !== (null !== $this->statusBeforeClaim)) {
            throw new \LogicException('claimedBy, claimedAt, and statusBeforeClaim are set exactly while the request is in_progress.');
        }

        if ((RequestStatus::WontDo === $this->status) !== (null !== $this->wontDoBy)) {
            throw new \LogicException('wontDoBy is set exactly while the request is wont_do.');
        }

        $resolutionFits = match ($this->status) {
            RequestStatus::Open, RequestStatus::InProgress, RequestStatus::Reopened => null === $this->resolution,
            RequestStatus::ReadyForReview => Resolution::Fixed === $this->resolution,
            RequestStatus::WontDo => Resolution::WontDo === $this->resolution,
            RequestStatus::Verified => null !== $this->resolution,
        };

        if (!$resolutionFits) {
            throw new \LogicException("The resolution does not fit the status {$this->status->value}.");
        }

        if ((ActorKind::User === $this->author->kind) !== (null === $this->suggestion)) {
            throw new \LogicException('suggestion is null exactly when a user is the author.');
        }

        $suggestionFits = match ($this->suggestion) {
            null, SuggestionState::Accepted => true,
            SuggestionState::Pending => RequestStatus::Open === $this->status,
            SuggestionState::Dismissed => RequestStatus::WontDo === $this->status && ActorKind::User === $this->wontDoBy,
        };

        if (!$suggestionFits) {
            throw new \LogicException("A {$this->suggestion?->value} suggestion does not fit the status {$this->status->value}.");
        }

        if (null === $this->proposal) {
            return;
        }

        if ($this->proposal->isPending() ? !$inProgress : !in_array($this->status, [RequestStatus::InProgress, RequestStatus::ReadyForReview, RequestStatus::Verified], true)) {
            throw new \LogicException("A {$this->proposal->state->value} proposal does not fit the status {$this->status->value}.");
        }
    }
}
