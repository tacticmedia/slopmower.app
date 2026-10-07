<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Anchor\Anchor;
use App\Domain\Identity\BatchId;
use App\Domain\Request\CarriedFrom;

final readonly class Open implements RequestCommand
{
    /**
     * @param Anchor|null $anchor Captured at the review commit. Null for a general request.
     */
    public function __construct(
        public ?Anchor $anchor,
        public string $body,
        public ?BatchId $batchId = null,
        public ?CarriedFrom $carriedFrom = null,
        public bool $requireApproval = false,
    ) {
    }

    public function requestIds(): array
    {
        return [];
    }
}
