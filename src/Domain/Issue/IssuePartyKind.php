<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * Agent: any agent of the tenant (D134).
 */
enum IssuePartyKind: string
{
    case User = 'user';
    case Agent = 'agent';
}
