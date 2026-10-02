<?php

declare(strict_types=1);

namespace App\Git;

/**
 * One file of `git diff-tree --raw --numstat`. Paths are raw git paths, which a RepoPath may reject.
 */
final readonly class ChangedFile
{
    /**
     * @param string      $path      The new path, the old path for a deletion
     * @param string|null $oldPath   Renames and copies only
     * @param int|null    $additions Null for a binary file
     */
    public function __construct(
        public ChangeStatus $status,
        public string $path,
        public ?string $oldPath,
        public ?string $oldMode,
        public ?string $newMode,
        public ?string $oldObjectId,
        public ?string $newObjectId,
        public ?int $additions,
        public ?int $deletions,
    ) {
    }

    public function isBinary(): bool
    {
        return null === $this->additions;
    }
}
