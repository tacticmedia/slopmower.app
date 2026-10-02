<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;

final readonly class LinkCommits implements RequestCommand
{
    /**
     * @param list<RequestId> $requestIds
     */
    public function __construct(
        public CommitSha $commitSha,
        public array $requestIds,
        public ?string $note = null,
    ) {
    }

    public function requestIds(): array
    {
        return $this->requestIds;
    }
}
