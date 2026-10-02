<?php

declare(strict_types=1);

namespace App\Domain\Review;

enum ReviewState: string
{
    case Open = 'open';
    case Closed = 'closed';
}
