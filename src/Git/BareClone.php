<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The bare server clone of a repository, docs/specs section 7. The caller holds the {@see RepositoryLock}.
 * The timeout of a method applies to all of its git commands together.
 */
final class BareClone
{
    public const array REFSPECS = ['+refs/heads/*:refs/heads/*', '+refs/tags/*:refs/tags/*'];

    public function __construct(
        private readonly GitRunner $runner,
        private readonly TenantDataPaths $paths,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Creates the bare repository with the object format of the remote. An empty remote gets SHA-1, the default of git.
     * An existing clone with the same object format stays as it is.
     *
     * @throws GitCommandFailed
     */
    public function init(TenantId $tenantId, RepositoryId $repositoryId, GitRemote $remote, float $timeoutSeconds = GitCommand::DEFAULT_TIMEOUT_SECONDS): void
    {
        $deadlineSeconds = $this->nowSeconds() + $timeoutSeconds;
        $cloneDirectory = $this->paths->cloneDirectory($tenantId, $repositoryId);
        $remoteHeadCommand = self::remoteHeadCommand($cloneDirectory, $remote, $timeoutSeconds);
        new Filesystem()->mkdir($cloneDirectory);

        $remoteHead = $this->runner->mustRun($this->beforeDeadline($remoteHeadCommand, $deadlineSeconds))->output;
        $objectFormat = 1 === preg_match('/^[0-9a-f]{64}\t/m', $remoteHead) ? 'sha256' : 'sha1';
        $this->runner->mustRun($this->beforeDeadline(
            new GitCommand(['init', '--bare', '--quiet', '--object-format='.$objectFormat], $cloneDirectory, $timeoutSeconds),
            $deadlineSeconds,
        ));
    }

    /**
     * Fetches the heads and the tags, then the pending SHAs by ID into their commit pins, then points HEAD to the
     * default branch of the remote. HEAD stays as it is when the remote has no default branch.
     *
     * @param list<CommitSha> $pendingShas
     *
     * @throws GitCommandFailed for every failure except that of a pending SHA
     */
    public function fetch(
        TenantId $tenantId,
        RepositoryId $repositoryId,
        GitRemote $remote,
        array $pendingShas = [],
        float $timeoutSeconds = GitCommand::DEFAULT_TIMEOUT_SECONDS,
    ): FetchResult {
        $deadlineSeconds = $this->nowSeconds() + $timeoutSeconds;
        $cloneDirectory = $this->paths->cloneDirectory($tenantId, $repositoryId);

        $remoteHead = $this->runner->mustRun($this->beforeDeadline(self::remoteHeadCommand($cloneDirectory, $remote, $timeoutSeconds), $deadlineSeconds))->output;
        $this->runner->mustRun($this->beforeDeadline(self::headsFetchCommand($cloneDirectory, $remote, $timeoutSeconds), $deadlineSeconds));
        $fetchedPendingShas = $this->fetchPendingShas($cloneDirectory, $remote, $pendingShas, $timeoutSeconds, $deadlineSeconds);

        $defaultBranch = self::defaultBranch($remoteHead);
        if (null !== $defaultBranch) {
            $this->runner->mustRun($this->beforeDeadline(
                new GitCommand(['symbolic-ref', '--end-of-options', 'HEAD', 'refs/heads/'.$defaultBranch->toString()], $cloneDirectory, $timeoutSeconds),
                $deadlineSeconds,
            ));
        }

        return new FetchResult($defaultBranch, $fetchedPendingShas);
    }

    public static function headsFetchCommand(string $cloneDirectory, GitRemote $remote, float $timeoutSeconds = GitCommand::DEFAULT_TIMEOUT_SECONDS): GitCommand
    {
        // --no-auto-maintenance: git starts no background process in the clone.
        return $remote->command('fetch', ['--no-auto-maintenance', '--prune'], self::REFSPECS, $cloneDirectory, $timeoutSeconds);
    }

    /**
     * @param non-empty-list<CommitSha> $pendingShas
     */
    public static function pendingShasFetchCommand(
        string $cloneDirectory,
        GitRemote $remote,
        array $pendingShas,
        float $timeoutSeconds = GitCommand::DEFAULT_TIMEOUT_SECONDS,
    ): GitCommand {
        // --no-tags: else git also fetches the tags that point to the fetched commits.
        return $remote->command(
            'fetch',
            ['--no-auto-maintenance', '--no-tags'],
            array_map(static fn (CommitSha $sha): string => $sha->toString().':'.PinRefs::commitRef($sha), $pendingShas),
            $cloneDirectory,
            $timeoutSeconds,
        );
    }

    /**
     * One unknown SHA fails a whole fetch with exit code 128, and git writes no ref. After a failed batch, each SHA gets
     * its own fetch, and its failure is ignored.
     *
     * @param list<CommitSha> $pendingShas
     *
     * @return list<CommitSha>
     */
    private function fetchPendingShas(string $cloneDirectory, GitRemote $remote, array $pendingShas, float $timeoutSeconds, float $deadlineSeconds): array
    {
        $uniqueShas = array_values(array_unique($pendingShas));
        if ([] === $uniqueShas) {
            return [];
        }
        $batchFetch = self::pendingShasFetchCommand($cloneDirectory, $remote, $uniqueShas, $timeoutSeconds);
        if ($this->runner->run($this->beforeDeadline($batchFetch, $deadlineSeconds))->isSuccessful()) {
            return $uniqueShas;
        }

        $fetchedShas = [];
        foreach ($uniqueShas as $sha) {
            $singleFetch = self::pendingShasFetchCommand($cloneDirectory, $remote, [$sha], $timeoutSeconds);
            if ($this->runner->run($this->beforeDeadline($singleFetch, $deadlineSeconds))->isSuccessful()) {
                $fetchedShas[] = $sha;
            }
        }

        return $fetchedShas;
    }

    private static function remoteHeadCommand(string $cloneDirectory, GitRemote $remote, float $timeoutSeconds): GitCommand
    {
        return $remote->command('ls-remote', ['--symref'], ['HEAD'], $cloneDirectory, $timeoutSeconds);
    }

    private static function defaultBranch(string $remoteHead): ?RefName
    {
        if (1 !== preg_match('#^ref: refs/heads/(\S+)\tHEAD$#m', $remoteHead, $match)) {
            return null;
        }

        try {
            return RefName::fromString($match[1]);
        } catch (InvalidInput) {
            // A branch that git accepts but a RefName does not, such as one that starts with a dash.
            return null;
        }
    }

    /**
     * @throws GitCommandFailed when the deadline has passed
     */
    private function beforeDeadline(GitCommand $command, float $deadlineSeconds): GitCommand
    {
        $secondsLeft = $deadlineSeconds - $this->nowSeconds();
        if ($secondsLeft <= 0.0) {
            throw GitCommandFailed::timedOut($command, '');
        }

        return new GitCommand($command->arguments, $command->workingDirectory, min($secondsLeft, $command->timeoutSeconds), $command->secrets, $command->input);
    }

    private function nowSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
