<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Git\CommitSha;

/**
 * A commit that is present in the server clone, with the git data of `commit.linked`.
 */
final readonly class AvailableCommit
{
    public function __construct(
        public CommitSha $commitSha,
        public string $subject,
        public \DateTimeImmutable $committedAt,
    ) {
    }
}
