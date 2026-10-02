<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;

/**
 * One request as {@see RequestReducer} folds it from the request's thread. {@see AwaitingPolicy} derives `awaiting`.
 */
final readonly class RequestState
{
    /**
     * @param list<LinkedCommit> $links In link order
     */
    public function __construct(
        public RequestId $id,
        public ReviewId $reviewId,
        public Anchor $anchor,
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
        public ?int $lastUserCommentSeq,
        public ?int $lastAgentEventSeq,
        public bool $lastAgentEventIsQuestion,
        public int $lastEventSeq,
        public \DateTimeImmutable $updatedAt,
    ) {
        $this->assertConsistent();
    }

    /**
     * @param array{status?: RequestStatus, statusBeforeClaim?: ?RequestStatus, claimedBy?: ?Actor, claimedAt?: ?\DateTimeImmutable, wontDoBy?: ?ActorKind, resolution?: ?Resolution, links?: list<LinkedCommit>, lastUserCommentSeq?: ?int, lastAgentEventSeq?: ?int, lastAgentEventIsQuestion?: bool, lastEventSeq?: int, updatedAt?: \DateTimeImmutable} $changes
     */
    public function with(array $changes): self
    {
        $state = clone ($this, $changes);
        $state->assertConsistent();

        return $state;
    }

    public function closed(): bool
    {
        return RequestStatus::Verified === $this->status
            || (RequestStatus::WontDo === $this->status && ActorKind::User === $this->wontDoBy);
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
    }
}
