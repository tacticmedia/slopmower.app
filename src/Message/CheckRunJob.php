<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\PullRequestId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Creates or updates the check run of a pull request record: docs/specs/webhooks-and-live-updates.md, "Check run".
 */
#[AsMessage('async')]
final readonly class CheckRunJob
{
    /**
     * @param bool $newRun For `check_run` `rerequested`
     */
    public function __construct(
        public PullRequestId $pullRequestId,
        public bool $newRun = false,
    ) {
    }
}
