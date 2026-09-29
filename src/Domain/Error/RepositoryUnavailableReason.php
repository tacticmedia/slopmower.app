<?php

declare(strict_types=1);

namespace App\Domain\Error;

enum RepositoryUnavailableReason: string
{
    case Cloning = 'cloning';
    case CloneFailed = 'clone_failed';
    case FetchFailed = 'fetch_failed';
    case Removed = 'removed';
}
