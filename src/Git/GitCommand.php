<?php

declare(strict_types=1);

namespace App\Git;

use Symfony\Component\Filesystem\Path;

final readonly class GitCommand
{
    public const float DEFAULT_TIMEOUT_SECONDS = 60.0;
    public const string REDACTED = '[redacted]';

    // A secret can leak in parts, for example the token of `AUTHORIZATION: basic <token>`.
    private const int MIN_REDACTED_PART_BYTES = 8;

    /**
     * @param list<string>          $arguments        the arguments after `git`
     * @param string                $workingDirectory absolute. Git looks for a repository only in this directory, never in a parent.
     * @param float                 $timeoutSeconds   for a long-lived process: per request
     * @param list<GitConfigSecret> $secrets
     */
    public function __construct(
        #[\SensitiveParameter]
        public array $arguments,
        public string $workingDirectory,
        public float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public array $secrets = [],
        public ?string $input = null,
    ) {
        if ([] === $arguments) {
            throw new \InvalidArgumentException('A git command needs at least one argument.');
        }
        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('A git argument must not contain a NUL byte.');
            }
            if ($this->redact($argument) !== $argument) {
                throw new \InvalidArgumentException('A secret must not appear in the git arguments, decision D38.');
            }
        }
        if (!Path::isAbsolute($workingDirectory)) {
            throw new \InvalidArgumentException(\sprintf('The git working directory "%s" must be absolute.', $workingDirectory));
        }
        if ($timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('The git timeout must be positive.');
        }
    }

    public function redact(string $text): string
    {
        $needles = [];
        foreach ($this->secrets as $secret) {
            $needles[] = $secret->value;
            foreach (preg_split('/\s+/', $secret->value, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (\strlen($part) >= self::MIN_REDACTED_PART_BYTES) {
                    $needles[] = $part;
                }
            }
        }
        usort($needles, static fn (string $first, string $second): int => \strlen($second) <=> \strlen($first));

        return str_replace($needles, self::REDACTED, $text);
    }

    public function describe(): string
    {
        return 'git '.implode(' ', $this->arguments);
    }
}
