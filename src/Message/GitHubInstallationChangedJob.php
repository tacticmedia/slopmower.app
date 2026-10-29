<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * `installation` `suspend`, `unsuspend`, and `new_permissions_accepted`. The handler reads the installation, because
 * deliveries and their retries arrive in any order (D97).
 */
#[AsMessage('async')]
final readonly class GitHubInstallationChangedJob
{
    public function __construct(public int $installationId)
    {
    }
}
