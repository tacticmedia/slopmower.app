<?php

declare(strict_types=1);

namespace App\Git;

/**
 * The `status` of commit-diff.schema.json.
 */
enum ChangeStatus: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
    case Copied = 'copied';
    case TypeChanged = 'type_changed';

    /**
     * @param string $letter The status letter of `git diff --raw`, without the score
     *
     * @throws GitFailed For U and X, which a diff of two commits never gives
     */
    public static function fromRawLetter(string $letter): self
    {
        return match ($letter) {
            'A' => self::Added,
            'M' => self::Modified,
            'D' => self::Deleted,
            'R' => self::Renamed,
            'C' => self::Copied,
            'T' => self::TypeChanged,
            default => throw new GitFailed("git diff-tree returned the status {$letter}."),
        };
    }

    public function hasTwoPaths(): bool
    {
        return self::Renamed === $this || self::Copied === $this;
    }
}
