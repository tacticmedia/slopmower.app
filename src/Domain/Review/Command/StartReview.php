<?php

declare(strict_types=1);

namespace App\Domain\Review\Command;

use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;

final readonly class StartReview
{
    /**
     * @param CommitSha $commitSha The ref resolved in the server clone
     */
    public function __construct(
        public string $title,
        public RefName $ref,
        public CommitSha $commitSha,
    ) {
    }
}
