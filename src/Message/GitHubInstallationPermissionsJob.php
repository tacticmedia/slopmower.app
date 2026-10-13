<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * `installation` `new_permissions_accepted`.
 */
#[AsMessage('async')]
final readonly class GitHubInstallationPermissionsJob
{
    /**
     * @param array<string, string> $permissions
     */
    public function __construct(
        public int $installationId,
        public array $permissions,
    ) {
    }
}
