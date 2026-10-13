<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\ActorKind;

enum RequestStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case ReadyForReview = 'ready_for_review';
    case Verified = 'verified';
    case Reopened = 'reopened';
    case WontDo = 'wont_do';

    /**
     * `verified`, or a `wont_do` that the user set.
     */
    public function isClosed(?ActorKind $wontDoBy): bool
    {
        return self::Verified === $this || (self::WontDo === $this && ActorKind::User === $wontDoBy);
    }
}
