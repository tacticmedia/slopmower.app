<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * D111. A local repository is always {@see self::Clone}.
 */
enum CodeAccess: string
{
    case Clone = 'clone';
    case PassThrough = 'pass_through';
}
