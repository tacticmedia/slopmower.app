<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;

/**
 * Schema-shaped: `blame.schema.json#/$defs/BlameRange`.
 */
final readonly class BlameRange
{
    public function __construct(
        public int $startLine,
        public int $endLine,
        public CommitSha $commitSha,
    ) {
    }
}
