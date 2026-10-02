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
     * Fetches the heads and tags, then the pending SHAs into their pins, then points HEAD at the default branch.
     * The timeout covers all of it.
     *
     * @param list<CommitSha>       $pendingShas
     * @param array<string, string> $secretConfiguration
     *
     * @throws GitFailed
     */
    public function fetch(string $gitDir, string $url, array $pendingShas = [], float $timeoutSeconds = GitRunner::TIMEOUT_SECONDS, array $secretConfiguration = []): void
    {
        $deadlineNanoseconds = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $hasTimedOut = static fn (): bool => hrtime(true) >= $deadlineNanoseconds;
        $run = function (string ...$arguments) use ($gitDir, $timeoutSeconds, $secretConfiguration, $deadlineNanoseconds): string {
            $remainingSeconds = ($deadlineNanoseconds - hrtime(true)) / 1e9;

            if ($remainingSeconds <= 0) {
                throw new GitFailed('git '.implode(' ', $arguments)." timed out after {$timeoutSeconds} s");
            }

            return $this->git->run($gitDir, array_values($arguments), $remainingSeconds, $secretConfiguration);
        };

        $run('fetch', '--no-auto-maintenance', '--prune', '--end-of-options', $url, ...self::REFSPECS);

        if ([] !== $pendingShas) {
            $refspecs = array_map(static fn (CommitSha $sha): string => "{$sha}:".PinRefs::COMMITS.$sha, $pendingShas);

            try {
                $run('fetch', '--no-auto-maintenance', '--end-of-options', $url, ...$refspecs);
            } catch (GitFailed $failure) {
                if ($hasTimedOut()) {
                    throw $failure;
                }

                // One SHA that the source does not have fails the whole fetch.
                foreach ($refspecs as $refspec) {
                    try {
                        $run('fetch', '--no-auto-maintenance', '--end-of-options', $url, $refspec);
                    } catch (GitFailed $failure) {
                        if ($hasTimedOut()) {
                            throw $failure;
                        }
                    }
                }
            }
        }

        if (1 === preg_match('~^ref: (refs/heads/\S+)\tHEAD$~m', $run('ls-remote', '--symref', '--end-of-options', $url, 'HEAD'), $match)) {
            $run('symbolic-ref', 'HEAD', $match[1]);
        }
    }
}
