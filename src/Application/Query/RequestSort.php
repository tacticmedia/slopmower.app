<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * The `sort` of Q1.
 */
enum RequestSort: string
{
    /** `id` ascending. */
    case Created = 'created';
    /** `updatedAt` descending, then `id`. */
    case Updated = 'updated';
    /** `anchor.path`, then `anchor.range.startLine` with file anchors first, then `id`. */
    case Path = 'path';
}
