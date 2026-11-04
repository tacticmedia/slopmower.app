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
     * @param bool        $blobsRead False for a change of Browser only, whose counts and binary flag nobody knows
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
        private bool $blobsRead = true,
    ) {
    }

    public function isBinary(): ?bool
    {
        return $this->blobsRead ? null === $this->additions : null;
    }
}
