<?php

declare(strict_types=1);

namespace App\Git;

final readonly class TreeEntry
{
    private const string SYMLINK_MODE = '120000';

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

    public function isSymlink(): bool
    {
        return ObjectType::Blob === $this->type && self::SYMLINK_MODE === $this->mode;
    }

    /**
     * The last path segment.
     */
    public function name(): string
    {
        $slash = strrpos($this->path, '/');

        return false === $slash ? $this->path : substr($this->path, $slash + 1);
    }
}
