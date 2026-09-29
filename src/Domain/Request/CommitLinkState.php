<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum CommitLinkState: string
{
    case Pending = 'pending';
    case Available = 'available';
}
