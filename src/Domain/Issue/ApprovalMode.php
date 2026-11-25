<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * Who approves a plan and ends an issue of a project (D117). ByIssue: the approval of each issue, one of the other cases
 * (D129).
 */
enum ApprovalMode: string
{
    case User = 'user';
    case AgentReview = 'agent_review';
    case Self = 'self';
    case ByIssue = 'by_issue';
}
