<?php

declare(strict_types=1);

namespace App\Domain\Issue;

enum IssueStatus: string
{
    case Draft = 'draft';
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Done = 'done';
    case WontDo = 'wont_do';

    public function isEnded(): bool
    {
        return self::Done === $this || self::WontDo === $this;
    }
}
