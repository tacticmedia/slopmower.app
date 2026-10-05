<?php

declare(strict_types=1);

namespace App\Message;

use App\GitHub\GitHubAccountType;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
final readonly class GitHubInstallationCreatedJob
{
    public function __construct(
        public int $installationId,
        public string $accountLogin,
        public GitHubAccountType $accountType,
        public ?\DateTimeImmutable $suspendedAt,
    ) {
    }
}
