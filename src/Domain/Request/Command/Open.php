<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Anchor\Anchor;
use App\Domain\Identity\BatchId;

final readonly class Open implements RequestCommand
{
    /**
     * @param Anchor $anchor Captured at the review commit
     */
    public function __construct(
        public Anchor $anchor,
        public string $body,
        public ?BatchId $batchId = null,
    ) {
    }

    public function requestIds(): array
    {
        return [];
    }
}
