<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

final readonly class Claim implements EventOnlyCommand
{
    public function __construct(public RequestId $requestId)
    {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
