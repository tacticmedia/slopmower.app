<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum SuggestionState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
