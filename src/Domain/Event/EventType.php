<?php

declare(strict_types=1);

namespace App\Domain\Event;

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
    case BatchSubmitted = 'batch.submitted';

    /**
     * Review, commit, and batch events have no requestId in the envelope.
     */
    public function hasRequestId(): bool
    {
        return str_starts_with($this->value, 'request.');
    }
}
