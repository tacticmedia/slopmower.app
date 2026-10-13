<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

final readonly class AcceptSuggestion implements RequestCommand
{
    /**
     * @param bool|null $requireApproval Null keeps the current value
     */
    public function __construct(
        public RequestId $requestId,
        public ?bool $requireApproval = null,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
