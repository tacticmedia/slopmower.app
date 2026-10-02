<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps one `cat-file --batch` process per clone until {@see reset()}, which runs between requests and messages.
 */
final class GitReader implements ResetInterface
{
    private const string HEADS = 'refs/heads/';

    /** @var array<string, CatFileBatch> */
    private array $batches = [];

    public function __construct(private readonly GitRunner $git)
    {
    }

    /**
     * @throws CommitNotFound
     * @throws InvalidInput   For a short SHA that matches more than one commit
     */
    public function resolveCommit(string $gitDir, RefName $ref): CommitSha
    {
        return $this->resolve($gitDir, $ref->toString(), 'ref');
    }

    /**
     * @param string $field Of the InvalidInput
     *
     * @throws CommitNotFound
     * @throws InvalidInput   For a prefix that matches more than one commit
     */
    public function resolvePrefix(string $gitDir, CommitShaPrefix $prefix, string $field = 'commit'): CommitSha
    {
        $commit = $this->resolve($gitDir, $prefix->toString(), $field);

        // A ref with the same name as the prefix wins in rev-parse.
        if (!str_starts_with($commit->toString(), $prefix->toString())) {
            throw new CommitNotFound($prefix->toString());
        }

        return $commit;
    }

    /**
     * @return string|null The branch that HEAD names, for example `main`. Null for a detached HEAD.
     */
    public function headBranch(string $gitDir): ?string
    {
        try {
            $ref = trim($this->git->run($gitDir, ['symbolic-ref', '--quiet', 'HEAD']));
        } catch (GitFailed) {
            return null;
        }

        return str_starts_with($ref, self::HEADS) ? substr($ref, strlen(self::HEADS)) : null;
    }

    public function hasCommit(string $gitDir, CommitSha $commit): bool
    {
        try {
            $this->git->run($gitDir, ['cat-file', '-e', '--end-of-options', "{$commit}^{commit}"]);

            return true;
        } catch (GitFailed) {
            return false;
        }
    }

    /**
     * The committer time, and the subject with invalid UTF-8 replaced, because event payloads are JSON.
     *
     * @throws CommitNotFound
     */
    public function commitInfo(string $gitDir, CommitSha $commit): CommitInfo
    {
        try {
            $output = $this->git->run($gitDir, ['log', '-1', '--format=%ct%x00%s', '--end-of-options', "{$commit}^{commit}"]);
        } catch (GitFailed) {
            throw new CommitNotFound($commit->toString());
        }

        [$committedAtUnixSeconds, $subject] = explode("\0", rtrim($output, "\n"), 2) + [1 => ''];

        return new CommitInfo($commit, mb_scrub($subject, 'UTF-8'), new \DateTimeImmutable('@'.$committedAtUnixSeconds));
    }

    /**
     * The raw author identity, without the mailmap of the repository. The committer time.
     *
     * @throws CommitNotFound
     */
    public function commitDetails(string $gitDir, CommitSha $commit): CommitDetails
    {
        try {
            // `format:` adds no line feed after the message.
            $output = $this->git->run($gitDir, ['log', '-1', '--no-mailmap', '--format=format:%P%x00%an%x00%ae%x00%ct%x00%s%x00%B', '--end-of-options', "{$commit}^{commit}"]);
        } catch (GitFailed) {
            throw new CommitNotFound($commit->toString());
        }

        [$parents, $authorName, $authorEmail, $committedAtUnixSeconds, $subject, $message] = explode("\0", $output, 6) + ['', '', '', '0', '', ''];
        $firstParent = explode(' ', $parents)[0];

        return new CommitDetails(
            $commit,
            '' === $firstParent ? null : CommitSha::fromString($firstParent),
            mb_scrub($subject, 'UTF-8'),
            mb_scrub($message, 'UTF-8'),
            mb_scrub($authorName, 'UTF-8'),
            mb_scrub($authorEmail, 'UTF-8'),
            new \DateTimeImmutable('@'.$committedAtUnixSeconds),
        );
    }

    /**
     * `diff-tree -r -M --raw --numstat` of the commit against its first parent, or against the empty tree.
     *
     * @return list<ChangedFile> In git's order
     *
     * @throws GitFailed
     */
    public function changedFiles(string $gitDir, ?CommitSha $parent, CommitSha $commit): array
    {
        $revisions = null === $parent ? ['--root', '--end-of-options', $commit->toString()] : ['--end-of-options', $parent->toString(), $commit->toString()];
        $records = explode("\0", rtrim($this->git->run($gitDir, ['diff-tree', '-r', '-M', '-z', '--raw', '--numstat', '--no-commit-id', ...$revisions]), "\0"));
        $index = 0;
        $raw = [];

        // Paths are read by position, so a path that starts with ":" is no raw record.
        while (1 === preg_match('/^:(\d{6}) (\d{6}) ([0-9a-f]{40,64}) ([0-9a-f]{40,64}) ([A-Z])\d*$/D', $records[$index] ?? '', $match)) {
            $status = ChangeStatus::fromRawLetter($match[5]);
            $raw[] = [$status, $match, $records[$index + 1] ?? '', $status->hasTwoPaths() ? $records[$index + 2] ?? '' : null];
            $index += $status->hasTwoPaths() ? 3 : 2;
        }

        // The numstat records follow in the same order. A rename has an empty path field, then both paths.
        return array_map(static function (array $entry) use ($records, &$index): ChangedFile {
            [$status, $match, $firstPath, $secondPath] = $entry;
            [$additions, $deletions, $numstatPath] = explode("\t", $records[$index] ?? '', 3) + ['', '', ''];
            $index += '' === $numstatPath ? 3 : 1;
            $isAdded = ChangeStatus::Added === $status;
            $isDeleted = ChangeStatus::Deleted === $status;

            return new ChangedFile(
                $status,
                $secondPath ?? $firstPath,
                null === $secondPath ? null : $firstPath,
                $isAdded ? null : $match[1],
                $isDeleted ? null : $match[2],
                $isAdded ? null : $match[3],
                $isDeleted ? null : $match[4],
                '-' === $additions ? null : (int) $additions,
                '-' === $deletions ? null : (int) $deletions,
            );
        }, $raw);
    }

    /**
     * `cat-file --batch-check`.
     *
     * @param list<string> $objectIds
     *
     * @return array<string, int> The size in bytes by object ID, without missing objects
     *
     * @throws GitFailed
     */
    public function objectSizes(string $gitDir, array $objectIds): array
    {
        if ([] === $objectIds) {
            return [];
        }

        $output = $this->git->run($gitDir, ['cat-file', '--batch-check=%(objectname) %(objectsize)'], standardInput: implode("\n", $objectIds)."\n");
        $sizes = [];

        foreach (explode("\n", $output) as $line) {
            if (1 === preg_match('/^([0-9a-f]{40,64}) (\d+)$/D', $line, $match)) {
                $sizes[$match[1]] = (int) $match[2];
            }
        }

        return $sizes;
    }

    /**
     * @return list<TreeEntry>
     *
     * @throws GitFailed When the directory is missing or not a directory
     */
    public function lsTree(string $gitDir, CommitSha $commit, ?RepoPath $directory = null): array
    {
        return $this->listTree($gitDir, $commit, $directory, []);
    }

    /**
     * @return list<TreeEntry> Blobs and submodules, without trees
     *
     * @throws GitFailed When the directory is missing or not a directory
     */
    public function lsTreeRecursive(string $gitDir, CommitSha $commit, ?RepoPath $directory = null): array
    {
        return $this->listTree($gitDir, $commit, $directory, ['-r']);
    }

    /**
     * @return TreeEntry|null Null when nothing has this path at the commit
     *
     * @throws GitFailed
     */
    public function entryAt(string $gitDir, CommitSha $commit, RepoPath $path): ?TreeEntry
    {
        $slash = strrpos($path->toString(), '/');
        $parent = false === $slash ? null : RepoPath::fromString(substr($path->toString(), 0, $slash));

        try {
            $siblings = $this->lsTree($gitDir, $commit, $parent);
        } catch (GitFailed $failure) {
            // GitRunner sets LC_ALL=C, so the messages of a missing parent and of a file or submodule parent are stable.
            $isMissingParent = null !== $parent && (str_ends_with($failure->getMessage(), ": fatal: Not a valid object name {$commit}:{$parent}")
                || str_ends_with($failure->getMessage(), ': fatal: not a tree object'));

            return $isMissingParent ? null : throw $failure;
        }

        return array_find($siblings, static fn (TreeEntry $entry): bool => $entry->path === $path->toString());
    }

    /**
     * Starts a process of its own, so the stream does not hold the shared batch process of the clone.
     *
     * @return StreamedBlob|null Null when the object is missing or not a blob
     *
     * @throws GitFailed
     */
    public function openBlob(string $gitDir, BlobSha $blob): ?StreamedBlob
    {
        return StreamedBlob::open($this->git, $gitDir, $blob);
    }

    /**
     * @throws GitFailed
     */
    public function blobAt(string $gitDir, CommitSha $commit, RepoPath $path): ?Blob
    {
        return $this->readFromBatch($gitDir, "{$commit}:{$path}");
    }

    /**
     * @throws GitFailed
     */
    public function readBlob(string $gitDir, BlobSha $blob): ?Blob
    {
        return $this->readFromBatch($gitDir, $blob->toString());
    }

    /**
     * `diff --name-status -M`, architecture section 5.4 `mapPath`.
     *
     * @return string|null The raw path of $path at $to: the new path of a rename, else $path. Null when it is deleted.
     *
     * @throws GitFailed
     */
    public function pathAfter(string $gitDir, CommitSha $from, CommitSha $to, RepoPath $path): ?string
    {
        $records = explode("\0", rtrim($this->git->run($gitDir, ['diff', '--name-status', '-M', '-z', '--no-color', '--end-of-options', $from->toString(), $to->toString(), '--']), "\0"));
        $index = 0;

        // A record is the status and one path, or for a rename the status, the old path, and the new path.
        while (isset($records[$index + 1])) {
            $status = $records[$index][0] ?? '';

            if ($records[$index + 1] === $path->toString()) {
                return match ($status) {
                    'D' => null,
                    'R' => $records[$index + 2] ?? throw new GitFailed('git diff --name-status returned a rename without a new path.'),
                    default => $path->toString(),
                };
            }

            $index += 'R' === $status ? 3 : 2;
        }

        return $path->toString();
    }

    /**
     * The `--unified=0` diff of two blobs, one file section that {@see \App\Domain\Anchoring\UnifiedZeroDiffParser}
     * reads. Pathspecs would also match the files of a directory that took the name of the file.
     *
     * @throws GitFailed
     */
    public function unifiedZeroDiff(string $gitDir, BlobSha $from, BlobSha $to): string
    {
        return $this->git->run($gitDir, ['diff', '--unified=0', '--no-color', '--no-ext-diff', '--no-textconv', '--text', '--end-of-options', $from->toString(), $to->toString()]);
    }

    /**
     * `git grep -F` without binary files.
     *
     * @return list<string> The raw paths of the files at the commit that contain the text, in path order
     *
     * @throws GitFailed
     */
    public function filesContaining(string $gitDir, CommitSha $commit, string $fixedText): array
    {
        try {
            $output = $this->git->run($gitDir, ['grep', '--files-with-matches', '-z', '-I', '-F', '--no-color', '-e', $fixedText, '--end-of-options', $commit->toString()]);
        } catch (GitFailed $failure) {
            // git grep exits with 1 when no file matches.
            return 1 === $failure->exitCode ? [] : throw $failure;
        }

        $prefix = "{$commit}:";

        return array_values(array_map(
            static fn (string $name): string => str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : throw new GitFailed('git grep returned a name without the commit.'),
            array_filter(explode("\0", $output), static fn (string $name): bool => '' !== $name),
        ));
    }

    public function reset(): void
    {
        foreach ($this->batches as $batch) {
            $batch->stop();
        }

        $this->batches = [];
    }

    /**
     * The `^{commit}` peel makes git count only commits as candidates of a short SHA.
     */
    private function resolve(string $gitDir, string $revision, string $field): CommitSha
    {
        try {
            return CommitSha::fromString(trim($this->git->run($gitDir, ['rev-parse', '--verify', '--end-of-options', "{$revision}^{commit}"])));
        } catch (GitFailed $failure) {
            // GitRunner sets LC_ALL=C, so the message is stable.
            throw str_contains($failure->getMessage(), "short object ID {$revision} is ambiguous") ? InvalidInput::field($field, "{$revision} matches more than one commit.") : new CommitNotFound($revision);
        }
    }

    private function readFromBatch(string $gitDir, string $objectName): ?Blob
    {
        $batch = $this->batches[$gitDir] ??= CatFileBatch::start($this->git, $gitDir);

        try {
            return $batch->blob($objectName);
        } catch (GitFailed $failure) {
            $batch->stop();
            unset($this->batches[$gitDir]);

            throw $failure;
        }
    }

    /**
     * @param list<string> $options
     *
     * @return list<TreeEntry>
     */
    private function listTree(string $gitDir, CommitSha $commit, ?RepoPath $directory, array $options): array
    {
        $treeish = null === $directory ? $commit->toString() : "{$commit}:{$directory}";
        $pathPrefix = null === $directory ? '' : "{$directory}/";

        return $this->treeEntries($this->git->run($gitDir, ['ls-tree', ...$options, '--long', '-z', '--end-of-options', $treeish]), $pathPrefix);
    }

    /**
     * @return list<TreeEntry>
     */
    private function treeEntries(string $output, string $pathPrefix): array
    {
        $entries = [];

        foreach (explode("\0", rtrim($output, "\0")) as $record) {
            if (1 === preg_match('/^(\d{6}) ([a-z]+) ([0-9a-f]{40,64}) +(-|\d+)\t(.+)$/sD', $record, $match)) {
                $entries[] = new TreeEntry($pathPrefix.$match[5], $match[1], ObjectType::from($match[2]), $match[3], '-' === $match[4] ? null : (int) $match[4]);
            }
        }

        return $entries;
    }
}
