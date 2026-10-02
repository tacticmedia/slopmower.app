<?php

declare(strict_types=1);

namespace App\Message;

use App\Webhook\Handler\InstallationHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see InstallationHandler::handleDeleted()}, under the tenant of its TenantStamp.
 */
#[AsMessage('async')]
final readonly class GitHubInstallationDeletedJob
{
    public function __construct(public int $installationId)
    {
    }
}
