<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;

/**
 * Schema-shaped: `commit.schema.json`.
 */
final readonly class LogCommit
{
    public function __construct(
        public CommitSha $sha,
        public string $subject,
        public string $authorName,
        public \DateTimeImmutable $authoredAt,
        public \DateTimeImmutable $committedAt,
    ) {
    }
}
