<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The caller holds the {@see RepositoryLock}.
 */
final class BareClone
{
    private const array REFSPECS = ['+refs/heads/*:refs/heads/*', '+refs/tags/*:refs/tags/*'];

    private const int BYTES_PER_KIB = 1024;

    public function __construct(private readonly GitRunner $git)
    {
    }

    /**
     * Creates the missing parent directories, which `git --git-dir=<path> init` does not.
     */
    public function init(string $gitDir): void
    {
        new Filesystem()->mkdir($gitDir);
        $this->git->run($gitDir, ['init', '--bare', '--quiet']);
    }

    /**
     * @return list<string> The arguments of the heads and tags fetch
     */
    public static function fetchArguments(string $url, ?string $uploadPack = null): array
    {
        return ['fetch', ...self::remoteOptions($uploadPack), '--no-auto-maintenance', '--prune', '--end-of-options', $url, ...self::REFSPECS];
    }

    /**
     * Fetches the heads and tags, points HEAD at the default branch, then fetches the pending SHAs into their pins.
     * The timeout covers all of it. The pending SHAs stop at the timeout without failing the fetch (D90).
     *
     * @param list<CommitSha>       $pendingShas
     * @param array<string, string> $secretConfiguration
     * @param string|null           $uploadPack          The `--upload-pack` command, which reads the source
     *
     * @throws GitFailed
     */
    public function fetch(string $gitDir, string $url, array $pendingShas = [], float $timeoutSeconds = GitRunner::TIMEOUT_SECONDS, #[\SensitiveParameter] array $secretConfiguration = [], ?string $uploadPack = null): void
    {
        $deadlineNanoseconds = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $run = function (string ...$arguments) use ($gitDir, $timeoutSeconds, $secretConfiguration, $deadlineNanoseconds): string {
            $remainingSeconds = ($deadlineNanoseconds - hrtime(true)) / 1e9;

            if ($remainingSeconds <= 0) {
                throw new GitFailed('git '.implode(' ', $arguments)." timed out after {$timeoutSeconds} s", "git {$arguments[0]} timed out after {$timeoutSeconds} s");
            }

            return $this->git->run($gitDir, array_values($arguments), $remainingSeconds, $secretConfiguration);
        };

        $remoteOptions = self::remoteOptions($uploadPack);
        $run(...self::fetchArguments($url, $uploadPack));

        if (1 === preg_match('~^ref: (refs/heads/\S+)\tHEAD$~m', $run('ls-remote', ...$remoteOptions, ...['--symref', '--end-of-options', $url, 'HEAD']), $match)) {
            $run('symbolic-ref', 'HEAD', $match[1]);
        }

        if ([] === $pendingShas) {
            return;
        }

        $this->fetchEach($gitDir, $url, array_map(static fn (CommitSha $sha): string => "{$sha}:".PinRefs::COMMITS.$sha, $pendingShas), $deadlineNanoseconds, $secretConfiguration, $uploadPack);
    }

    /**
     * Fetches commits by SHA without a ref, for the pins that the caller writes. A SHA that the source lacks is skipped,
     * and the timeout ends the fetch without a failure.
     *
     * @param list<CommitSha>       $shas
     * @param array<string, string> $secretConfiguration
     */
    public function fetchCommits(string $gitDir, string $url, array $shas, float $timeoutSeconds, #[\SensitiveParameter] array $secretConfiguration = [], ?string $uploadPack = null): void
    {
        $this->fetchEach($gitDir, $url, array_map(static fn (CommitSha $sha): string => $sha->toString(), $shas), hrtime(true) + (int) ($timeoutSeconds * 1e9), $secretConfiguration, $uploadPack);
    }

    /**
     * D92: the fetches run without auto maintenance. In the foreground, so it ends before the caller releases the
     * {@see RepositoryLock}.
     *
     * @throws GitFailed
     */
    public function collectGarbage(string $gitDir, float $timeoutSeconds): void
    {
        $this->git->run($gitDir, ['-c', 'gc.autoDetach=false', 'gc', '--auto', '--quiet'], $timeoutSeconds);
    }

    /**
     * `size` and `size-pack` of `git count-objects -v`: git keeps the objects of a fetch of fewer than 100 objects
     * loose (`fetch.unpackLimit`).
     *
     * @throws GitFailed
     */
    public function objectSizeBytes(string $gitDir): int
    {
        $output = $this->git->run($gitDir, ['count-objects', '-v']);

        if (1 !== preg_match('/^size: (\d+)$/m', $output, $loose) || 1 !== preg_match('/^size-pack: (\d+)$/m', $output, $packed)) {
            throw new GitFailed('git count-objects -v printed no size or size-pack.');
        }

        return ((int) $loose[1] + (int) $packed[1]) * self::BYTES_PER_KIB;
    }

    /**
     * One fetch of every refspec, else one fetch per refspec until the deadline: one SHA that the source does not have
     * fails a whole fetch.
     *
     * @param list<string>          $refspecs
     * @param array<string, string> $secretConfiguration
     */
    private function fetchEach(string $gitDir, string $url, array $refspecs, int $deadlineNanoseconds, #[\SensitiveParameter] array $secretConfiguration, ?string $uploadPack): void
    {
        $fetch = function (array $batch) use ($gitDir, $url, $deadlineNanoseconds, $secretConfiguration, $uploadPack): bool {
            $remainingSeconds = ($deadlineNanoseconds - hrtime(true)) / 1e9;

            if ($remainingSeconds <= 0) {
                return false;
            }

            try {
                $this->git->run($gitDir, array_values(['fetch', ...self::remoteOptions($uploadPack), '--no-auto-maintenance', '--end-of-options', $url, ...$batch]), $remainingSeconds, $secretConfiguration);

                return true;
            } catch (GitFailed) {
                return false;
            }
        };

        if ([] === $refspecs || $fetch($refspecs)) {
            return;
        }

        foreach ($refspecs as $refspec) {
            if (hrtime(true) >= $deadlineNanoseconds) {
                return;
            }

            $fetch([$refspec]);
        }
    }

    /**
     * @return list<string>
     */
    private static function remoteOptions(?string $uploadPack): array
    {
        return null === $uploadPack ? [] : ["--upload-pack={$uploadPack}"];
    }
}
