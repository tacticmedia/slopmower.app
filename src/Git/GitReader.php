<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the server clone. Blob contents come through one `cat-file --batch` process per clone, until reset().
 * A missing or broken clone gives {@see GitCommandFailed}.
 */
final class GitReader implements ResetInterface
{
    // `ls-tree --long -z`: `<mode> <type> <object> <size, padded>\t<path>`, size `-` for trees and submodules.
    private const string LS_TREE_RECORD_PATTERN = '/^([0-7]{6}) (blob|tree|commit) ([0-9a-f]{40}|[0-9a-f]{64}) +(-|\d+)\t(.+)$/sD';

    /**
     * @var array<string, GitProcess> by clone directory
     */
    private array $batchProcesses = [];

    public function __construct(
        private readonly GitRunner $runner,
        private readonly TenantDataPaths $paths,
    ) {
    }

    /**
     * @throws CommitNotFound
     * @throws InvalidInput   for a SHA prefix of more than one commit
     */
    public function resolveCommit(TenantId $tenantId, RepositoryId $repositoryId, RefName $ref, string $field = 'ref'): CommitSha
    {
        $clone = $this->paths->cloneDirectory($tenantId, $repositoryId);
        $command = new GitCommand(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref->toString().'^{commit}'], $clone);
        $result = $this->runner->run($command);
        if ($result->isSuccessful()) {
            return CommitSha::fromString(trim($result->output));
        }
        if (1 !== $result->exitCode) {
            throw GitCommandFailed::exited($command, $result->exitCode, $result->errorOutput);
        }

        try {
            $prefix = CommitShaPrefix::fromString($ref->toString(), $field);
        } catch (InvalidInput) {
            throw new CommitNotFound($ref->toString());
        }

        return $this->commitWithPrefix($clone, $prefix, $field);
    }

    /**
     * Only object IDs count, never refs.
     *
     * @throws CommitNotFound
     * @throws InvalidInput   for a prefix of more than one commit
     */
    public function resolvePrefix(TenantId $tenantId, RepositoryId $repositoryId, CommitShaPrefix $prefix, string $field = 'commit'): CommitSha
    {
        return $this->commitWithPrefix($this->paths->cloneDirectory($tenantId, $repositoryId), $prefix, $field);
    }

    /**
     * The availability check of docs/specs section 7.
     */
    public function hasCommit(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $sha): bool
    {
        $clone = $this->paths->cloneDirectory($tenantId, $repositoryId);
        if ($this->runner->run(new GitCommand(['cat-file', '-e', '--end-of-options', $sha->toString().'^{commit}'], $clone))->isSuccessful()) {
            return true;
        }

        // cat-file -e exits with 128 for a missing commit and for a broken clone alike.
        $this->runner->mustRun(new GitCommand(['rev-parse', '--git-dir'], $clone));

        return false;
    }

    /**
     * @return list<TreeEntry> the direct entries, in git order
     *
     * @throws NotFound when the directory is not a directory at the commit
     */
    public function lsTree(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, ?RepoPath $directory = null): array
    {
        return $this->listTree($tenantId, $repositoryId, $commit, $directory, []);
    }

    /**
     * @return list<TreeEntry> the files, symlinks, and submodules under the directory, by path in byte order
     *
     * @throws NotFound when the directory is not a directory at the commit
     */
    public function lsTreeRecursive(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, ?RepoPath $directory = null): array
    {
        return $this->listTree($tenantId, $repositoryId, $commit, $directory, ['-r']);
    }

    /**
     * @return TreeEntry|null null when the commit has no entry at the path
     */
    public function entryAt(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, RepoPath $path): ?TreeEntry
    {
        $output = $this->runner->mustRun(new GitCommand(
            ['--literal-pathspecs', 'ls-tree', '--long', '-z', '--end-of-options', $commit->toString(), $path->toString()],
            $this->paths->cloneDirectory($tenantId, $repositoryId),
        ))->output;

        foreach (self::treeEntries($output, '') as $entry) {
            if ($entry->path === $path->toString()) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Reads the whole blob. Check {@see TreeEntry::$sizeBytes} of {@see entryAt()} first when the size matters.
     *
     * @throws NotFound when the path is not a file or a symlink at the commit
     */
    public function blobAt(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, RepoPath $path): Blob
    {
        $entry = $this->entryAt($tenantId, $repositoryId, $commit, $path);
        if (null === $entry || !$entry->type->isBlob()) {
            throw NotFound::resource('File', $path);
        }

        return new Blob($entry, $this->readBlob($tenantId, $repositoryId, BlobSha::fromString($entry->sha)));
    }

    /**
     * @throws NotFound when the clone has no blob with this SHA
     */
    public function readBlob(TenantId $tenantId, RepositoryId $repositoryId, BlobSha $sha): BlobContent
    {
        $response = $this->batchRequest($this->paths->cloneDirectory($tenantId, $repositoryId), $sha->toString());

        $headerLength = (int) strpos($response, "\n");
        $header = explode(' ', substr($response, 0, $headerLength));
        if (3 !== \count($header) || 'blob' !== $header[1]) {
            throw NotFound::resource('Blob', $sha);
        }

        return new BlobContent(substr($response, $headerLength + 1, (int) $header[2]));
    }

    /**
     * Stops the `cat-file --batch` processes. The service resetter calls it between requests and messages.
     */
    public function reset(): void
    {
        foreach ($this->batchProcesses as $process) {
            $process->close();
        }
        $this->batchProcesses = [];
    }

    private function commitWithPrefix(string $clone, CommitShaPrefix $prefix, string $field): CommitSha
    {
        $objectIds = self::lines($this->runner->mustRun(new GitCommand(['rev-parse', '--disambiguate='.$prefix->toString()], $clone))->output);

        $commitIds = [];
        if ([] !== $objectIds) {
            $objectInfos = $this->runner->mustRun(new GitCommand(['cat-file', '--batch-check'], $clone, input: implode("\n", $objectIds)."\n"))->output;
            foreach (self::lines($objectInfos) as $objectInfo) {
                $objectInfoFields = explode(' ', $objectInfo);
                if ('commit' === ($objectInfoFields[1] ?? null)) {
                    $commitIds[] = $objectInfoFields[0];
                }
            }
        }

        return match (\count($commitIds)) {
            0 => throw new CommitNotFound($prefix->toString()),
            1 => CommitSha::fromString($commitIds[0]),
            default => throw InvalidInput::field($field, 'matches more than one commit'),
        };
    }

    /**
     * @param list<string> $options
     *
     * @return list<TreeEntry>
     */
    private function listTree(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, ?RepoPath $directory, array $options): array
    {
        [$treeIsh, $pathPrefix] = [$commit->toString(), ''];
        if (null !== $directory) {
            $entry = $this->entryAt($tenantId, $repositoryId, $commit, $directory);
            if (TreeEntryType::Directory !== $entry?->type) {
                throw NotFound::resource('Directory', $directory);
            }
            [$treeIsh, $pathPrefix] = [$entry->sha, $directory->toString().'/'];
        }

        $output = $this->runner->mustRun(new GitCommand(
            ['ls-tree', ...$options, '--long', '-z', '--end-of-options', $treeIsh],
            $this->paths->cloneDirectory($tenantId, $repositoryId),
        ))->output;

        return self::treeEntries($output, $pathPrefix);
    }

    private function batchRequest(string $clone, string $objectId): string
    {
        $process = $this->batchProcesses[$clone] ?? null;
        if (null === $process || !$process->isRunning()) {
            $process = $this->runner->start(new GitCommand(['cat-file', '--batch'], $clone));
            $this->batchProcesses[$clone] = $process;
        }

        try {
            return $process->request($objectId."\n", self::batchResponseLength(...));
        } catch (GitCommandFailed $failure) {
            unset($this->batchProcesses[$clone]);

            throw $failure;
        }
    }

    /**
     * `<object> <type> <size>\n<contents>\n`, or `<object> missing\n`.
     */
    private static function batchResponseLength(string $output): ?int
    {
        $headerLength = strpos($output, "\n");
        if (false === $headerLength) {
            return null;
        }
        $header = explode(' ', substr($output, 0, $headerLength));

        return 3 === \count($header) ? $headerLength + 1 + (int) $header[2] + 1 : $headerLength + 1;
    }

    /**
     * @return list<TreeEntry>
     */
    private static function treeEntries(string $lsTreeOutput, string $pathPrefix): array
    {
        $entries = [];
        foreach (explode("\0", rtrim($lsTreeOutput, "\0")) as $record) {
            if ('' === $record) {
                continue;
            }
            if (1 !== preg_match(self::LS_TREE_RECORD_PATTERN, $record, $fields)) {
                throw new \UnexpectedValueException(\sprintf('git ls-tree printed the unexpected record "%s".', $record));
            }

            /** @var 'blob'|'tree'|'commit' $objectType */
            [, $mode, $objectType, $sha, $size, $relativePath] = $fields;
            $path = $pathPrefix.$relativePath;
            $lastSlash = strrpos($path, '/');
            $entries[] = new TreeEntry(
                $path,
                false === $lastSlash ? $path : substr($path, $lastSlash + 1),
                TreeEntryType::fromGit($objectType, $mode),
                $mode,
                $sha,
                '-' === $size ? null : (int) $size,
            );
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $output): array
    {
        return preg_split('/\n/', $output, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
