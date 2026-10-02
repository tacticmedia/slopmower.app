<?php

declare(strict_types=1);

namespace App\Git;

final readonly class TreeEntry
{
    public const int MAX_RENDERABLE_BYTES = 1_048_576;

    private const string SYMLINK_MODE = '120000';

    private const array REGULAR_FILE_MODES = ['100644', '100755'];

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
     * A regular file of at most 1 MiB. Its content is renderable when it is not binary as well.
     */
    public function isRenderableFile(): bool
    {
        return ObjectType::Blob === $this->type
            && in_array($this->mode, self::REGULAR_FILE_MODES, true)
            && null !== $this->sizeBytes && $this->sizeBytes <= self::MAX_RENDERABLE_BYTES;
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
