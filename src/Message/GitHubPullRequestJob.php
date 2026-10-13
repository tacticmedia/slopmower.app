<?php

declare(strict_types=1);

namespace App\Message;

use App\GitHub\Payload\PullRequestPayload;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A `pull_request` delivery for a number that the tenant links.
 */
#[AsMessage('async')]
final readonly class GitHubPullRequestJob
{
    public function __construct(
        public int $githubRepositoryId,
        public PullRequestPayload $pullRequest,
    ) {
    }
}
