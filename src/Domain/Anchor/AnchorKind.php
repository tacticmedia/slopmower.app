<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

enum AnchorKind: string
{
    case Line = 'line';
    case Range = 'range';
    case Symbol = 'symbol';
    case File = 'file';
}
