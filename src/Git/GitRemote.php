<?php

declare(strict_types=1);

namespace App\Git;

/**
 * The source of a server clone: `file://<realpath>` or `https://github.com/<owner>/<name>.git`, docs/specs section 7.
 */
final readonly class GitRemote
{
    // An HTTPS URL has no user information, so a token can never reach the arguments through it, decision D38.
    private const string URL_PATTERN = '#^(?:file:///[^\x00-\x1f\x7f]*|https://[^/@\x00-\x20\x7f]+(?:/[^\x00-\x1f\x7f]*)?)$#D';

    /**
     * @param list<string>          $configuration `<key>=<value>` entries that git gets as `-c` options, for example `safe.directory=<realpath>`
     * @param list<GitConfigSecret> $secrets
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $url,
        public array $configuration = [],
        public array $secrets = [],
    ) {
        if (1 !== preg_match(self::URL_PATTERN, $url)) {
            throw new \InvalidArgumentException('A git remote URL must be a file:/// URL, or an https:// URL without user information, and contain no control characters.');
        }
        foreach ($configuration as $entry) {
            if (1 !== preg_match('/^[A-Za-z][^=]*=/', $entry)) {
                throw new \InvalidArgumentException(\sprintf('The git configuration entry "%s" must have the form <key>=<value>.', $entry));
            }
        }
    }

    /**
     * `git -c <configuration>... <subcommand> <options>... --end-of-options <url> <operands>...`.
     *
     * @param list<string> $options
     * @param list<string> $operands
     */
    public function command(string $subcommand, array $options, array $operands, string $workingDirectory, float $timeoutSeconds): GitCommand
    {
        $configurationOptions = [];
        foreach ($this->configuration as $entry) {
            $configurationOptions[] = '-c';
            $configurationOptions[] = $entry;
        }

        return new GitCommand(
            [...$configurationOptions, $subcommand, ...$options, '--end-of-options', $this->url, ...$operands],
            $workingDirectory,
            $timeoutSeconds,
            $this->secrets,
        );
    }
}
