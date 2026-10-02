<?php

declare(strict_types=1);

namespace App\Domain\Location;

/**
 * The targets of architecture section 5.2.
 */
enum LocationTarget: string
{
    case DefaultBranch = 'default_branch';
    case Requested = 'requested';
    case LinkedCommit = 'linked_commit';
}
