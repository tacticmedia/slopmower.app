<?php

declare(strict_types=1);

namespace App\Domain\Git;

/**
 * A commit that is present in the server clone.
 */
final readonly class CommitInfo
{
    public function __construct(
        public CommitSha $commitSha,
        public string $subject,
        public \DateTimeImmutable $committedAt,
    ) {
    }
}
