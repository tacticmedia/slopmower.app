<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

final readonly class SetTitle implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public string $title,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
