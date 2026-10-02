<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum RequestStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case ReadyForReview = 'ready_for_review';
    case Verified = 'verified';
    case Reopened = 'reopened';
    case WontDo = 'wont_do';
}
