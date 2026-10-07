<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Git\BlameCommit;
use App\Git\BlameRange;

/**
 * Schema-shaped: `blame.schema.json`. V17.
 */
final readonly class Blame
{
    /**
     * @param list<BlameRange>  $ranges
     * @param list<BlameCommit> $commits
     */
    public function __construct(
        public ReviewId $reviewId,
        public CommitSha $commitSha,
        public RepoPath $path,
        public BlobSha $blobSha,
        public bool $ignoreRevsFile,
        public array $ranges,
        public array $commits,
    ) {
    }
}
