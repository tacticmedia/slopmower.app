<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;
use App\Domain\Repository\RepositoryName;

final readonly class LinkPullRequest implements RequestCommand
{
    /**
     * @param list<RequestId> $requestIds
     * @param RepositoryName  $repository The repository of the review, which a GitHub pull request URL must name
     */
    public function __construct(
        public string $url,
        public array $requestIds,
        public RepositoryName $repository,
        public ?string $note = null,
    ) {
    }

    public function requestIds(): array
    {
        return $this->requestIds;
    }
}
