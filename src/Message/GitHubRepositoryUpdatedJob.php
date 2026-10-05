<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Repository\RepositoryName;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A `repository` event other than `deleted`.
 */
#[AsMessage('async')]
final readonly class GitHubRepositoryUpdatedJob
{
    /**
     * @param bool $renamed `renamed` or `transferred`: the name changes too
     */
    public function __construct(
        public int $githubRepositoryId,
        public RepositoryName $fullName,
        public bool $private,
        public bool $renamed,
    ) {
    }
}
