<?php

declare(strict_types=1);

namespace App\Domain\Location;

enum OrphanReason: string
{
    case FileDeleted = 'file_deleted';
    case RegionDeleted = 'region_deleted';
    case Ambiguous = 'ambiguous';
    case ContentNotFound = 'content_not_found';
}
