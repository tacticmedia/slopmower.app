<?php

declare(strict_types=1);

namespace App\Domain\Issue;

enum IssueEntryKind: string
{
    case Comment = 'comment';
    case StatusChanged = 'status_changed';
    case SectionChanged = 'section_changed';
    case PlanApproved = 'plan_approved';
}
