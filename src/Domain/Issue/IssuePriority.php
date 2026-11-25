<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * The place of a new issue among the open issues of its category, in rank order (D137). Not stored.
 */
enum IssuePriority: string
{
    case Top = 'top';
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
    case None = 'none';

    /**
     * ceil($openIssues x p), with p the share of the priority: the open issues that stay before the new issue.
     */
    public function issuesBefore(int $openIssues): int
    {
        $sharePercent = match ($this) {
            self::Top => 0,
            self::Urgent => 10,
            self::High => 25,
            self::Normal => 50,
            self::Low => 75,
            self::None => 100,
        };

        return intdiv($openIssues * $sharePercent + 99, 100);
    }
}
