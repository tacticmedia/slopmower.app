<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\RepoPath;
use App\Git\ChangedFile;

/**
 * `CommitDiffFile` of commit-diff.schema.json.
 */
final readonly class CommitDiffFileView
{
    public function __construct(
        public ChangedFile $change,
        public RepoPath $path,
        public ?RepoPath $oldPath,
        public bool $tooLarge,
        public bool $touchesAnchor,
    ) {
    }
}
