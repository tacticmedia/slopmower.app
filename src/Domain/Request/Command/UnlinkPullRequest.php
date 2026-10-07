<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;
use App\Domain\Repository\RepositoryName;

final readonly class UnlinkPullRequest implements RequestCommand
{
    /**
     * @param RepositoryName $repository The repository of the review, which gives a GitHub URL its stored form
     */
    public function __construct(
        public RequestId $requestId,
        public string $url,
        public RepositoryName $repository,
    ) {
    }

    public function requestIds(): array
    {
        return [$this->requestId];
    }
}
