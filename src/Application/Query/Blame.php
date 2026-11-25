<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Git\BlameRange;

/**
 * Schema-shaped: `blame.schema.json`. V17.
 */
final readonly class Blame
{
    /**
     * @param list<BlameRange>                                                                                                                              $ranges
     * @param list<array{sha: CommitSha, authorName: string, authoredAt: \DateTimeImmutable, summary: string, contributorIndex: int, htmlUrl: string|null}> $commits
     * @param list<array{name: string, avatarUrl: string}>                                                                                                  $contributors
     */
    public function __construct(
        public ReviewId $reviewId,
        public CommitSha $commitSha,
        public RepoPath $path,
        public BlobSha $blobSha,
        public bool $ignoreRevsFile,
        public array $ranges,
        public array $commits,
        public array $contributors,
    ) {
    }
}
