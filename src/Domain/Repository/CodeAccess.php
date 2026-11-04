<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * D111, D114. A local repository is always {@see self::Clone}.
 */
enum CodeAccess: string
{
    case Clone = 'clone';
    case PassThrough = 'pass_through';
    /** The server reads metadata only, and the browser reads file content from GitHub. */
    case Browser = 'browser';
}
