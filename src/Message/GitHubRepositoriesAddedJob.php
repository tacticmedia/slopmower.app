<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Repository\RepositoryName;
use App\Webhook\Handler\InstallationHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see InstallationHandler::handleRepositoriesAdded()}, under the tenant of its TenantStamp.
 */
#[AsMessage('async')]
final readonly class GitHubRepositoriesAddedJob
{
    /**
     * @param array<int, RepositoryName> $repositories By GitHub repository ID
     */
    public function __construct(
        public int $installationId,
        public string $accountLogin,
        public array $repositories,
    ) {
    }
}
