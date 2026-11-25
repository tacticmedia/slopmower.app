<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * The role of the party with the next step on an issue (D134).
 */
enum WaitingFor: string
{
    case Requester = 'requester';
    case Assignee = 'assignee';

    /**
     * Null in a status where nobody has the next step.
     */
    public static function defaultFor(IssueStatus $status): ?self
    {
        return match ($status) {
            IssueStatus::Draft, IssueStatus::InReview => self::Requester,
            IssueStatus::Todo, IssueStatus::InProgress, IssueStatus::Approved => self::Assignee,
            IssueStatus::Backlog, IssueStatus::Done, IssueStatus::WontDo => null,
        };
    }
}
