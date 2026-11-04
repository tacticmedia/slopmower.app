<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;

/**
 * The code of one repository (D112). {@see \App\Application\CodeReaders} gives the reader of a repository by its code access. Every
 * method can throw {@see GitFailed}, and for Pass-through {@see RepositoryUnavailable} with `fetch_failed` and
 * {@see RateLimited}.
 */
interface CodeReader
{
    /**
     * @throws CommitNotFound
     * @throws InvalidInput   For a short SHA that matches more than one commit or tag
     */
    public function resolveCommit(RefName $ref): CommitSha;

    /**
     * @param string $field Of the InvalidInput
     *
     * @throws CommitNotFound
     * @throws InvalidInput   For a prefix that matches more than one commit or tag
     */
    public function resolvePrefix(CommitShaPrefix $prefix, string $field = 'commit'): CommitSha;

    public function hasCommit(CommitSha $commit): bool;

    /**
     * @throws CommitNotFound
     */
    public function commitInfo(CommitSha $commit): CommitInfo;

    /**
     * @throws CommitNotFound
     */
    public function commitDetails(CommitSha $commit): CommitDetails;

    /**
     * @return list<LogCommit> Newest first
     */
    public function log(CommitSha $from, int $limit): array;

    /**
     * The changes of the commit against its first parent, or against the empty tree.
     *
     * @return list<ChangedFile>
     */
    public function changedFiles(?CommitSha $parent, CommitSha $commit): array;

    /**
     * @param list<string> $objectIds
     *
     * @return array<string, int> The size in bytes by object ID, without unknown objects
     */
    public function objectSizes(array $objectIds): array;

    /**
     * @return list<TreeEntry>
     *
     * @throws GitFailed When the directory is missing or not a directory
     */
    public function lsTree(CommitSha $commit, ?RepoPath $directory = null): array;

    /**
     * @return list<TreeEntry> Blobs and submodules, without trees
     *
     * @throws GitFailed When the directory is missing or not a directory
     */
    public function lsTreeRecursive(CommitSha $commit, ?RepoPath $directory = null): array;

    /**
     * @return TreeEntry|null Null when nothing has this path at the commit
     */
    public function entryAt(CommitSha $commit, RepoPath $path): ?TreeEntry;

    /**
     * @return StreamedBlob|null Null when the object is missing or not a blob
     */
    public function openBlob(BlobSha $blob): ?StreamedBlob;

    /**
     * @return Blob|null Null when the object is missing or not a blob
     */
    public function readBlob(BlobSha $blob): ?Blob;

    /**
     * Architecture section 5.4 `mapPath`.
     *
     * @return string|null The raw path of $path at $to: the new path of a rename, else $path. Null when it is deleted.
     */
    public function pathAfter(CommitSha $from, CommitSha $to, RepoPath $path): ?string;

    /**
     * The `--unified=0` diff of two blobs, one file section that {@see \App\Domain\Matching\UnifiedZeroDiffParser}
     * reads.
     */
    public function unifiedZeroDiff(BlobSha $from, BlobSha $to): string;

    /**
     * `git grep -F` without binary files.
     *
     * @return list<string> The raw paths of the files at the commit that contain the text, in path order
     */
    public function filesContaining(CommitSha $commit, string $fixedText): array;

    /**
     * @param list<string> $ignoredCommits Full SHAs. Pass-through ignores them.
     *
     * @return array{ranges: list<BlameRange>, commits: list<BlameCommit>} Adjacent lines of one commit form one range.
     *                                                                     The commits are in the order of their first range.
     */
    public function blame(CommitSha $commit, RepoPath $path, array $ignoredCommits): array;

    /**
     * Calls back with a reader of a git directory that has the commit.
     *
     * @template T
     *
     * @param callable(GitCodeReader): T $callback
     *
     * @return T
     */
    public function withLocalCopy(CommitSha $commit, callable $callback): mixed;
}
