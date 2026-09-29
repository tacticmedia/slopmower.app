<?php

declare(strict_types=1);

namespace App\Domain\Repository;

enum RepositoryAccess: string
{
    case Active = 'active';
    case Removed = 'removed';
}
