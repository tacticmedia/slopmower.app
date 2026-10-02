<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

final readonly class Release implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public ?string $note = null,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
