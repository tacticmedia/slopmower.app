<?php

declare(strict_types=1);

namespace App\Git;

final readonly class TreeEntry
{
    /**
     * @param string   $path      Raw git path from the commit root. Git allows bytes that a RepoPath rejects.
     * @param int|null $sizeBytes Null for a tree or a submodule
     */
    public function __construct(
        public string $path,
        public string $mode,
        public ObjectType $type,
        public string $objectId,
        public ?int $sizeBytes,
    ) {
    }
}
