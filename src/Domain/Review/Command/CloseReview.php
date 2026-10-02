<?php

declare(strict_types=1);

namespace App\Domain\Review\Command;

final readonly class CloseReview
{
    public function __construct(public ?string $note = null)
    {
    }
}
