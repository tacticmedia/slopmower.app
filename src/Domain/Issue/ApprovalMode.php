<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * Who approves a plan and ends an issue of a project (D117).
 */
enum ApprovalMode: string
{
    case User = 'user';
    case AgentReview = 'agent_review';
    case Self = 'self';
}
