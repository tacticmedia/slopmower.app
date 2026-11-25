<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * The `sort` of I1 (D137).
 */
enum IssueSort: string
{
    /** The category, bugs first, then `rank`. Without a status, the open issues only. */
    case Rank = 'rank';
    /** `updatedAt` descending, then `id`. */
    case Updated = 'updated';
}
