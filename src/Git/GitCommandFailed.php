<?php

declare(strict_types=1);

namespace App\Git;

/**
 * The message and the error output have the secrets of the command redacted. No previous exception is kept,
 * because its message would not be redacted.
 */
final class GitCommandFailed extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?int $exitCode,
        public readonly string $errorOutput,
    ) {
        parent::__construct($message);
    }

    public static function exited(GitCommand $command, int $exitCode, #[\SensitiveParameter] string $errorOutput): self
    {
        return self::create($command, \sprintf('exited with code %d', $exitCode), $exitCode, $errorOutput);
    }

    public static function timedOut(GitCommand $command, #[\SensitiveParameter] string $errorOutput): self
    {
        return self::create($command, \sprintf('exceeded the timeout of %s seconds', $command->timeoutSeconds), null, $errorOutput);
    }

    public static function couldNotRun(GitCommand $command, #[\SensitiveParameter] string $reason): self
    {
        return self::create($command, 'did not run', null, $reason);
    }

    private static function create(GitCommand $command, string $outcome, ?int $exitCode, #[\SensitiveParameter] string $errorOutput): self
    {
        $redactedErrorOutput = $command->redact($errorOutput);

        return new self(
            $command->redact(\sprintf('%s %s in %s: %s', $command->describe(), $outcome, $command->workingDirectory, trim($redactedErrorOutput))),
            $exitCode,
            $redactedErrorOutput,
        );
    }
}
