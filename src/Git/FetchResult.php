<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;

final readonly class FetchResult
{
    /**
     * @param RefName|null    $defaultBranch      the branch that HEAD of the remote points to, null for an empty remote or a detached HEAD
     * @param list<CommitSha> $fetchedPendingShas the pending SHAs that git fetched into their commit pins
     */
    public function __construct(
        public ?RefName $defaultBranch,
        public array $fetchedPendingShas,
    ) {
    }
}
