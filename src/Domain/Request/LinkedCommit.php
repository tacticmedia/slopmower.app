<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Git\CommitSha;

final readonly class LinkedCommit
{
    public function __construct(
        public CommitSha $commitSha,
        public CommitLinkState $state,
    ) {
    }
}
