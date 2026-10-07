<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;

/**
 * Schema-shaped: `blame.schema.json#/$defs/BlameCommit`.
 */
final readonly class BlameCommit
{
    public function __construct(
        public CommitSha $sha,
        public string $authorName,
        public \DateTimeImmutable $authoredAt,
        public string $summary,
    ) {
    }
}
