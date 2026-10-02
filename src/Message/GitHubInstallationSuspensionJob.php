<?php

declare(strict_types=1);

namespace App\Message;

use App\Webhook\Handler\InstallationHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see InstallationHandler::handleSuspension()}, under the tenant of its TenantStamp.
 */
#[AsMessage('async')]
final readonly class GitHubInstallationSuspensionJob
{
    /**
     * @param \DateTimeImmutable|null $suspendedAt Null for `unsuspend`
     */
    public function __construct(
        public int $installationId,
        public ?\DateTimeImmutable $suspendedAt,
    ) {
    }
}
