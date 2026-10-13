<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Anchor\Anchor;

final readonly class Suggest implements RequestCommand
{
    /**
     * @param Anchor|null $anchor Captured at the review commit. Null for a general request.
     */
    public function __construct(
        public ?Anchor $anchor,
        public string $body,
    ) {
    }

    public function requestIds(): array
    {
        return [];
    }
}
