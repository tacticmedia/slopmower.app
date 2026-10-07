<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\CorruptPayload;

enum EventType: string
{
    case ReviewStarted = 'review.started';
    case ReviewClosed = 'review.closed';
    case RequestOpened = 'request.opened';
    case RequestCommented = 'request.commented';
    case RequestClaimed = 'request.claimed';
    case RequestReleased = 'request.released';
    case CommitLinked = 'commit.linked';
    case CommitUnlinked = 'commit.unlinked';
    case CommitFound = 'commit.found';
    case RequestReadyForReview = 'request.ready_for_review';
    case RequestWontDo = 'request.wont_do';
    case RequestVerified = 'request.verified';
    case RequestReopened = 'request.reopened';
    case RequestProposed = 'request.proposed';
    case RequestProposalApproved = 'request.proposal_approved';
    case RequestProposalRejected = 'request.proposal_rejected';
    case BatchSubmitted = 'batch.submitted';

    /**
     * @return class-string<DomainEvent>
     */
    public function eventClass(): string
    {
        return match ($this) {
            self::ReviewStarted => ReviewStartedEvent::class,
            self::ReviewClosed => ReviewClosedEvent::class,
            self::RequestOpened => RequestOpenedEvent::class,
            self::RequestCommented => RequestCommentedEvent::class,
            self::RequestClaimed => RequestClaimedEvent::class,
            self::RequestReleased => RequestReleasedEvent::class,
            self::CommitLinked => CommitLinkedEvent::class,
            self::CommitUnlinked => CommitUnlinkedEvent::class,
            self::CommitFound => CommitFoundEvent::class,
            self::RequestReadyForReview => RequestReadyForReviewEvent::class,
            self::RequestWontDo => RequestWontDoEvent::class,
            self::RequestVerified => RequestVerifiedEvent::class,
            self::RequestReopened => RequestReopenedEvent::class,
            self::RequestProposed => RequestProposedEvent::class,
            self::RequestProposalApproved => RequestProposalApprovedEvent::class,
            self::RequestProposalRejected => RequestProposalRejectedEvent::class,
            self::BatchSubmitted => BatchSubmittedEvent::class,
        };
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws CorruptPayload
     */
    public function decode(array $payload): DomainEvent
    {
        return $this->eventClass()::fromPayload($payload);
    }

    /**
     * The `requestId` column is set exactly for these. Review, batch, and commit events have none.
     */
    public function isRequestEvent(): bool
    {
        return str_starts_with($this->value, 'request.');
    }
}
