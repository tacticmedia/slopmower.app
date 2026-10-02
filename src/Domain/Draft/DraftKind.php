<?php

declare(strict_types=1);

namespace App\Domain\Draft;

enum DraftKind: string
{
    case NewRequest = 'new_request';
    case Reply = 'reply';
}
