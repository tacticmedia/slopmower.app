<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * `installation_repositories.removed` and `repository.deleted`.
 */
#[AsMessage('async')]
final readonly class GitHubRepositoriesRemovedJob
{
    /**
     * @param list<int> $githubRepositoryIds
     */
    public function __construct(public array $githubRepositoryIds)
    {
    }
}
