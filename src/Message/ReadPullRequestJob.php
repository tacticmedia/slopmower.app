<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\RepositoryId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * The first read of a pull request after `link_pull_request` on a GitHub repository.
 */
#[AsMessage('async')]
final readonly class ReadPullRequestJob
{
    public function __construct(
        public RepositoryId $repositoryId,
        public int $number,
    ) {
    }
}
