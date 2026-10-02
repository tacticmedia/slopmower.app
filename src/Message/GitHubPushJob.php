<?php

declare(strict_types=1);

namespace App\Message;

use App\Webhook\Handler\PushHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see PushHandler}, under the tenant of its TenantStamp.
 */
#[AsMessage('async')]
final readonly class GitHubPushJob
{
    public function __construct(public int $githubRepositoryId)
    {
    }
}
