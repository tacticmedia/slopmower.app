<?php

declare(strict_types=1);

namespace App\Git;

/**
 * The `type` of tree-entry.schema.json.
 */
enum TreeEntryType: string
{
    case Directory = 'directory';
    case File = 'file';
    case Symlink = 'symlink';
    case Submodule = 'submodule';

    private const string SYMLINK_MODE = '120000';

    /**
     * @param 'blob'|'tree'|'commit' $objectType
     */
    public static function fromGit(string $objectType, string $mode): self
    {
        return match ($objectType) {
            'tree' => self::Directory,
            'commit' => self::Submodule,
            'blob' => self::SYMLINK_MODE === $mode ? self::Symlink : self::File,
        };
    }

    public function isBlob(): bool
    {
        return self::File === $this || self::Symlink === $this;
    }
}
