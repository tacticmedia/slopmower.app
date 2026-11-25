<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\CommitSha;

/**
 * Schema-shaped: `issue.schema.json#/$defs/IssueCommits`. I12.
 */
final readonly class IssueCommits
{
    /**
     * @param list<array{repository: string, sha: CommitSha, subject: string, authorName: string, committedAt: \DateTimeImmutable, onDefaultBranch: bool, branch: string|null, htmlUrl: string|null}> $items
     * @param list<array{repository: string, reason: string}>                                                                                                                                         $unavailable
     */
    public function __construct(
        public array $items,
        public array $unavailable,
    ) {
    }
}
