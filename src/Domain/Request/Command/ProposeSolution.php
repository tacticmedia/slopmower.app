<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

final readonly class ProposeSolution implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public string $plan,
        public ?string $diff = null,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
