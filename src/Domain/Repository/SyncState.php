<?php

declare(strict_types=1);

namespace App\Domain\Repository;

enum SyncState: string
{
    case Cloning = 'cloning';
    case Failed = 'failed';
    case Idle = 'idle';
    case Fetching = 'fetching';
    case Unavailable = 'unavailable';
}
