<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;

final readonly class UnlinkCommit implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public CommitSha $commitSha,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
