<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * D112: temporary bare repositories of Pass-through under APP_SCRATCH_DIR, a tmpfs. In `prod` a directory that no
 * tmpfs holds is refused, so code never reaches a disk.
 */
final class ScratchCopies
{
    private const float FETCH_TIMEOUT_SECONDS = 300.0;

    /** A process that died on a fatal error left the copies that are older. */
    private const int LEFTOVER_AGE_SECONDS = 3600;

    private readonly string $root;

    public function __construct(
        private readonly GitRunner $git,
        #[Autowire(env: 'APP_SCRATCH_DIR')]
        string $scratchDir,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        private readonly ClockInterface $clock,
    ) {
        $this->root = Path::makeAbsolute($scratchDir, $projectDir);
    }

    /**
     * A `git fetch --depth=1` of one commit.
     *
     * @param array<string, string> $secretConfiguration Git configuration that reaches git only through GIT_CONFIG_* variables (D38)
     *
     * @throws RepositoryUnavailable With `fetch_failed`
     */
    public function copy(RepositoryId $repositoryId, string $url, #[\SensitiveParameter] array $secretConfiguration, CommitSha $commit): GitCodeReader
    {
        $copy = $this->emptyRepository();

        try {
            $this->git->run($copy->gitDir, ['fetch', '--depth=1', '--no-tags', '--no-auto-maintenance', '--quiet', '--end-of-options', $url, $commit->toString()], self::FETCH_TIMEOUT_SECONDS, $secretConfiguration);
        } catch (\Throwable $failure) {
            $this->remove($copy);

            throw $failure instanceof GitFailed ? new RepositoryUnavailable($repositoryId, RepositoryUnavailableReason::FetchFailed, $failure) : $failure;
        }

        return $copy;
    }

    public function emptyRepository(): GitCodeReader
    {
        if ('prod' === $this->environment && !self::onTmpfs($this->root)) {
            throw new \LogicException("APP_SCRATCH_DIR {$this->root} is not on a tmpfs.");
        }

        $this->removeLeftovers();
        $gitDir = Path::join($this->root, bin2hex(random_bytes(16)).'.git');
        $this->git->run($gitDir, ['init', '--bare', '--quiet']);

        return new GitCodeReader($this->git, $gitDir);
    }

    public function writeBlob(GitCodeReader $repository, string $bytes): void
    {
        $this->markInUse($repository);
        $this->git->run($repository->gitDir, ['hash-object', '-w', '--stdin'], standardInput: $bytes);
    }

    public function remove(GitCodeReader $copy): void
    {
        $copy->close();
        new Filesystem()->remove($copy->gitDir);
    }

    /**
     * The sweep of {@see removeLeftovers()} goes by the modification time, so each use renews it.
     */
    public function markInUse(GitCodeReader $copy): void
    {
        @touch($copy->gitDir, $this->clock->now()->getTimestamp());
    }

    private function removeLeftovers(): void
    {
        $oldestUnixSeconds = $this->clock->now()->getTimestamp() - self::LEFTOVER_AGE_SECONDS;

        foreach (glob(Path::join($this->root, '*.git'), \GLOB_ONLYDIR) ?: [] as $directory) {
            // Another process can remove it meanwhile.
            $modifiedUnixSeconds = @filemtime($directory);

            try {
                if (false !== $modifiedUnixSeconds && $modifiedUnixSeconds < $oldestUnixSeconds) {
                    new Filesystem()->remove($directory);
                }
            } catch (IOExceptionInterface) {
            }
        }
    }

    /**
     * The deepest mount that holds the directory decides.
     */
    private static function onTmpfs(string $directory): bool
    {
        $holder = ['', ''];

        foreach (@file('/proc/mounts', \FILE_IGNORE_NEW_LINES) ?: [] as $mount) {
            [, $mountPoint, $type] = explode(' ', $mount) + ['', '', ''];

            if (Path::isBasePath($mountPoint, $directory) && \strlen($mountPoint) >= \strlen($holder[0])) {
                $holder = [$mountPoint, $type];
            }
        }

        return 'tmpfs' === $holder[1];
    }
}
