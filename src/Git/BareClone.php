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
     * Fetches the heads and tags, then the pending SHAs into their pins, then points HEAD at the default branch.
     * The timeout covers all of it.
     *
     * @param list<CommitSha>       $pendingShas
     * @param array<string, string> $secretConfiguration
     * @param string|null           $uploadPack          The `--upload-pack` command, which reads the source
     *
     * @throws GitFailed
     */
    public function fetch(string $gitDir, string $url, array $pendingShas = [], float $timeoutSeconds = GitRunner::TIMEOUT_SECONDS, array $secretConfiguration = [], ?string $uploadPack = null): void
    {
        $deadlineNanoseconds = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $hasTimedOut = static fn (): bool => hrtime(true) >= $deadlineNanoseconds;
        $run = function (string ...$arguments) use ($gitDir, $timeoutSeconds, $secretConfiguration, $deadlineNanoseconds): string {
            $remainingSeconds = ($deadlineNanoseconds - hrtime(true)) / 1e9;

            if ($remainingSeconds <= 0) {
                throw new GitFailed('git '.implode(' ', $arguments)." timed out after {$timeoutSeconds} s", "git {$arguments[0]} timed out after {$timeoutSeconds} s");
            }

            return $this->git->run($gitDir, array_values($arguments), $remainingSeconds, $secretConfiguration);
        };

        $remoteOptions = self::remoteOptions($uploadPack);
        $run(...self::fetchArguments($url, $uploadPack));

        if ([] !== $pendingShas) {
            $refspecs = array_map(static fn (CommitSha $sha): string => "{$sha}:".PinRefs::COMMITS.$sha, $pendingShas);

            try {
                $run('fetch', ...$remoteOptions, ...['--no-auto-maintenance', '--end-of-options', $url, ...$refspecs]);
            } catch (GitFailed $failure) {
                if ($hasTimedOut()) {
                    throw $failure;
                }

                // One SHA that the source does not have fails the whole fetch.
                foreach ($refspecs as $refspec) {
                    try {
                        $run('fetch', ...$remoteOptions, ...['--no-auto-maintenance', '--end-of-options', $url, $refspec]);
                    } catch (GitFailed $failure) {
                        if ($hasTimedOut()) {
                            throw $failure;
                        }
                    }
                }
            }
        }

        if (1 === preg_match('~^ref: (refs/heads/\S+)\tHEAD$~m', $run('ls-remote', ...$remoteOptions, ...['--symref', '--end-of-options', $url, 'HEAD']), $match)) {
            $run('symbolic-ref', 'HEAD', $match[1]);
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
