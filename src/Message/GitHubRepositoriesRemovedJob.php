<?php

declare(strict_types=1);

namespace App\Message;

use App\Webhook\Handler\InstallationHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * `installation_repositories.removed` and `repository.deleted`. Handled by
 * {@see InstallationHandler::handleRepositoriesRemoved()}, under the tenant of its TenantStamp.
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
