<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;

final readonly class ApproveProposal implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public EventId $proposalEventId,
        public ?string $note = null,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
