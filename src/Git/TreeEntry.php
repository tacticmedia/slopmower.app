<?php

declare(strict_types=1);

namespace App\Git;

final readonly class TreeEntry
{
    /**
     * @param string   $path      from the repository root, as git stores it. Git allows paths that are no valid RepoPath,
     *                            for example with a leading `-` or with invalid UTF-8.
     * @param string   $sha       of the tree, the blob, or the submodule commit
     * @param int|null $sizeBytes files and symlinks only
     */
    public function __construct(
        public string $path,
        public string $name,
        public TreeEntryType $type,
        public string $mode,
        public string $sha,
        public ?int $sizeBytes,
    ) {
    }
}
