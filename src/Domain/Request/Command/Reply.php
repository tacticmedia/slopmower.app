<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\BatchId;
use App\Domain\Identity\RequestId;

final readonly class Reply implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public string $body,
        public bool $question = false,
        public ?BatchId $batchId = null,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
