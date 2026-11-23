<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * What a stored link is (D126). The target of a BlockedBy link reads it as `blocks`:
 * {@see \App\Entity\IssueLink::kindFor()}.
 */
enum IssueLinkKind: string
{
    case Relates = 'relates';
    case BlockedBy = 'blocked_by';
}
