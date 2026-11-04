<?php

declare(strict_types=1);

namespace App\Domain\Location;

enum LocationStatus: string
{
    case Exact = 'exact';
    case Shifted = 'shifted';
    case Changed = 'changed';
    case Orphaned = 'orphaned';
    /** D114: Browser only, the file changed and no code was read. */
    case Unverified = 'unverified';
}
