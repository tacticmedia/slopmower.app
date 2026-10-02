<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Review\ReviewState;

enum ReviewStateFilter: string
{
    case Open = 'open';
    case Closed = 'closed';
    case All = 'all';

    /**
     * @return ReviewState|null Null for every state
     */
    public function state(): ?ReviewState
    {
        return ReviewState::tryFrom($this->value);
    }
}
