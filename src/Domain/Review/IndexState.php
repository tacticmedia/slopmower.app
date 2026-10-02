<?php

declare(strict_types=1);

namespace App\Domain\Review;

enum IndexState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
}
