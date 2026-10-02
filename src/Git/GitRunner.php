<?php

declare(strict_types=1);

namespace App\Git;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class GitRunner
{
    public const float TIMEOUT_SECONDS = 60.0;

    private const array ENVIRONMENT = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'LC_ALL' => 'C',
    ];

    public const string REDACTED = '[redacted]';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $secretConfiguration Git configuration that reaches git only through GIT_CONFIG_* variables (D38)
     *
     * @return string The standard output
     *
     * @throws GitFailed
     */
    public function run(string $gitDir, array $arguments, float $timeoutSeconds = self::TIMEOUT_SECONDS, #[\SensitiveParameter] array $secretConfiguration = []): string
    {
        $process = $this->process($gitDir, $arguments, $secretConfiguration)->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw $this->failure($arguments, "timed out after {$timeoutSeconds} s", $secretConfiguration);
        }

        if (!$process->isSuccessful()) {
            throw $this->failure($arguments, "exited with {$process->getExitCode()}: ".trim($process->getErrorOutput()), $secretConfiguration, $process->getExitCode());
        }

        return $process->getOutput();
    }

    /**
     * Starts a long-running git process without a timeout. The caller enforces its own timeouts and stops it.
     *
     * @param list<string> $arguments
     */
    public function start(string $gitDir, array $arguments, InputStream $input): Process
    {
        $process = $this->process($gitDir, $arguments, [])->setTimeout(null)->setInput($input);
        $process->start();

        return $process;
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $secretConfiguration
     */
    private function process(string $gitDir, array $arguments, #[\SensitiveParameter] array $secretConfiguration): Process
    {
        $environment = self::ENVIRONMENT + ['GIT_CONFIG_COUNT' => (string) count($secretConfiguration)];

        foreach (array_keys($secretConfiguration) as $index => $key) {
            $environment["GIT_CONFIG_KEY_{$index}"] = $key;
            $environment["GIT_CONFIG_VALUE_{$index}"] = $secretConfiguration[$key];
        }

        return new Process(['git', '--git-dir='.$gitDir, ...$arguments], env: $environment);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $secretConfiguration
     */
    private function failure(array $arguments, string $reason, #[\SensitiveParameter] array $secretConfiguration, ?int $exitCode = null): GitFailed
    {
        $redact = static fn (string $text): string => str_replace(array_values($secretConfiguration), self::REDACTED, $text);
        $failure = new GitFailed($redact('git '.implode(' ', $arguments).' '.$reason), $redact("git {$arguments[0]} {$reason}"), $exitCode);
        $this->logger->debug($failure->getMessage());

        return $failure;
    }
}
