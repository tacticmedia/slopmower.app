<?php

declare(strict_types=1);

namespace App\Domain\Issue;

enum IssueSection: string
{
    case Problem = 'problem';
    case Plan = 'plan';
    case Result = 'result';
    case FollowUps = 'followUps';
    case Notes = 'notes';
}
