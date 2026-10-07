<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

final readonly class SetPlanRequirement implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public bool $requireApproval,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
