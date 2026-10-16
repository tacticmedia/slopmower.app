<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Anchor\Anchor;

final readonly class Suggest implements RequestCommand
{
    /**
     * @param Anchor|null $anchor Captured at the review commit. Null for a general request.
     * @param string|null $title  Required. {@see \App\Domain\Request\RequestDecider} refuses null.
     */
    public function __construct(
        public ?Anchor $anchor,
        public string $body,
        public ?string $title = null,
    ) {
    }

    public function requestIds(): array
    {
        return [];
    }
}
