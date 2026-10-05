<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

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
