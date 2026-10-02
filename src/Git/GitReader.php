<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
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
     * @throws CommitNotFound
     * @throws InvalidInput   For a prefix that matches more than one commit
     */
    public function resolvePrefix(string $gitDir, CommitShaPrefix $prefix): CommitSha
    {
        $commit = $this->resolve($gitDir, $prefix->toString(), 'commit');

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
     * @return list<TreeEntry>
     */
    public function lsTree(string $gitDir, CommitSha $commit, ?RepoPath $directory = null): array
    {
        $treeish = null === $directory ? $commit->toString() : "{$commit}:{$directory}";
        $pathPrefix = null === $directory ? '' : "{$directory}/";

        return $this->treeEntries($this->git->run($gitDir, ['ls-tree', '--long', '-z', '--end-of-options', $treeish]), $pathPrefix);
    }

    /**
     * @return list<TreeEntry> Blobs and submodules, without trees
     */
    public function lsTreeRecursive(string $gitDir, CommitSha $commit): array
    {
        return $this->treeEntries($this->git->run($gitDir, ['ls-tree', '-r', '--long', '-z', '--end-of-options', $commit->toString()]), '');
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
