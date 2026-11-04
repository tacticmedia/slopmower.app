<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Domain\Git\CommitSha;
use App\Git\ChangedFile;
use App\Git\ChangeStatus;
use App\Git\ObjectType;
use App\Git\TreeEntry;

/**
 * D114: the changes between two commits from their trees, as `git diff-tree -r` finds them, without a blob. The walk
 * descends only into the subtrees whose SHAs differ. A deleted and an added path of one blob SHA give a rename.
 */
final class TreeDiff
{
    private const string SYMLINK_MODE = '120000';

    private const string SUBMODULE_MODE = '160000';

    /**
     * @param \Closure(list<array{CommitSha, string|null}>, bool): void      $prefetch Lists the directories at their commits at once, recursive or not
     * @param \Closure(CommitSha, string|null, bool): (list<TreeEntry>|null) $listing  A listing, recursive or not, null for a missing directory
     *
     * @return list<ChangedFile> By path
     */
    public static function changes(?CommitSha $from, CommitSha $to, \Closure $prefetch, \Closure $listing): array
    {
        /** @var array<string, TreeEntry> $old */
        $old = [];
        /** @var array<string, TreeEntry> $new */
        $new = [];
        $directories = [null];

        while ([] !== $directories) {
            $prefetch(array_merge(...array_map(static fn (?string $directory): array => null === $from ? [[$to, $directory]] : [[$from, $directory], [$to, $directory]], $directories)), false);
            $differing = [];
            /** @var list<array{CommitSha, string, bool}> $subtrees Commit, path, and whether it is the old side */
            $subtrees = [];

            foreach ($directories as $directory) {
                $oldEntries = null === $from ? [] : self::byPath($listing($from, $directory, false) ?? []);
                $newEntries = self::byPath($listing($to, $directory, false) ?? []);

                foreach (array_keys($oldEntries + $newEntries) as $path) {
                    $oldEntry = $oldEntries[$path] ?? null;
                    $newEntry = $newEntries[$path] ?? null;

                    if ($oldEntry?->objectId === $newEntry?->objectId && $oldEntry?->mode === $newEntry?->mode) {
                        continue;
                    }

                    if (ObjectType::Tree === $oldEntry?->type && ObjectType::Tree === $newEntry?->type) {
                        $differing[] = (string) $path;

                        continue;
                    }

                    if (ObjectType::Tree === $oldEntry?->type && null !== $from) {
                        $subtrees[] = [$from, (string) $path, true];
                    } elseif (null !== $oldEntry) {
                        $old[(string) $path] = $oldEntry;
                    }

                    if (ObjectType::Tree === $newEntry?->type) {
                        $subtrees[] = [$to, (string) $path, false];
                    } elseif (null !== $newEntry) {
                        $new[(string) $path] = $newEntry;
                    }
                }
            }

            // The added and deleted subtrees of a level in one round of concurrent requests.
            $prefetch(array_map(static fn (array $subtree): array => [$subtree[0], $subtree[1]], $subtrees), true);

            foreach ($subtrees as [$commit, $path, $isOld]) {
                $entries = self::byPath($listing($commit, $path, true) ?? []);
                if ($isOld) {
                    $old += $entries;
                } else {
                    $new += $entries;
                }
            }

            $directories = $differing;
        }

        return self::paired($old, $new);
    }

    /**
     * @param array<string, TreeEntry> $old
     * @param array<string, TreeEntry> $new
     *
     * @return list<ChangedFile>
     */
    private static function paired(array $old, array $new): array
    {
        ksort($old, \SORT_STRING);
        ksort($new, \SORT_STRING);
        $changes = [];
        /** @var array<string, list<string>> $addedByObjectId */
        $addedByObjectId = [];

        foreach (array_diff_key($new, $old) as $path => $entry) {
            $addedByObjectId[$entry->objectId][] = (string) $path;
        }

        foreach ($old as $path => $entry) {
            $path = (string) $path;
            $newEntry = $new[$path] ?? null;

            if (null !== $newEntry) {
                $status = self::kind($entry->mode) === self::kind($newEntry->mode) ? ChangeStatus::Modified : ChangeStatus::TypeChanged;
                $changes[$path] = self::change($status, $path, null, $entry, $newEntry);

                continue;
            }

            $renamedTo = isset($addedByObjectId[$entry->objectId]) ? array_shift($addedByObjectId[$entry->objectId]) : null;
            $changes[$renamedTo ?? $path] = null === $renamedTo
                ? self::change(ChangeStatus::Deleted, $path, null, $entry, null)
                : self::change(ChangeStatus::Renamed, $renamedTo, $path, $entry, $new[$renamedTo]);
        }

        foreach ($addedByObjectId as $paths) {
            foreach ($paths as $path) {
                $changes[$path] = self::change(ChangeStatus::Added, $path, null, null, $new[$path]);
            }
        }

        ksort($changes, \SORT_STRING);

        return array_values($changes);
    }

    private static function change(ChangeStatus $status, string $path, ?string $oldPath, ?TreeEntry $old, ?TreeEntry $new): ChangedFile
    {
        return new ChangedFile($status, $path, $oldPath, $old?->mode, $new?->mode, $old?->objectId, $new?->objectId, null, null, blobsRead: false);
    }

    /**
     * Git reports a change between a file, a symlink, and a submodule as a type change.
     */
    private static function kind(string $mode): string
    {
        return match ($mode) {
            self::SYMLINK_MODE, self::SUBMODULE_MODE => $mode,
            default => 'file',
        };
    }

    /**
     * @param array<TreeEntry> $entries
     *
     * @return array<string, TreeEntry>
     */
    private static function byPath(array $entries): array
    {
        return array_column(array_map(static fn (TreeEntry $entry): array => ['path' => $entry->path, 'entry' => $entry], $entries), 'entry', 'path');
    }
}
