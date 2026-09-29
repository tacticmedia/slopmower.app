<?php

declare(strict_types=1);

namespace App\Git;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * The only way to run git. Of the inherited environment, git gets only PATH.
 */
#[WithMonologChannel('git')]
final class GitRunner
{
    private const array FIXED_ENVIRONMENT = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'LC_ALL' => 'C',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns a result for every exit code.
     *
     * @throws GitCommandFailed when git does not start, exceeds the timeout, or is killed
     */
    public function run(GitCommand $command): GitResult
    {
        $process = $this->process($command, $command->input);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw $this->logged(GitCommandFailed::timedOut($command, $process->getErrorOutput()));
        } catch (ProcessException $exception) {
            throw $this->logged(GitCommandFailed::couldNotRun($command, $exception->getMessage()));
        }

        $exitCode = $process->getExitCode() ?? throw $this->logged(GitCommandFailed::couldNotRun($command, 'no exit code'));
        $result = new GitResult($exitCode, $process->getOutput(), $command->redact($process->getErrorOutput()));
        $this->logger->debug('{command} exited with code {exitCode} in {workingDirectory}.', [
            'command' => $command->describe(),
            'exitCode' => $exitCode,
            'workingDirectory' => $command->workingDirectory,
            'errorOutput' => $result->errorOutput,
        ]);

        return $result;
    }

    /**
     * @throws GitCommandFailed also for an exit code other than 0
     */
    public function mustRun(GitCommand $command): GitResult
    {
        $result = $this->run($command);
        if (!$result->isSuccessful()) {
            throw GitCommandFailed::exited($command, $result->exitCode, $result->errorOutput);
        }

        return $result;
    }

    /**
     * Starts a long-lived git process that answers requests on stdin, such as `cat-file --batch`.
     *
     * @throws GitCommandFailed when git does not start
     */
    public function start(GitCommand $command): GitProcess
    {
        $input = new InputStream();
        $input->write($command->input);
        $process = $this->process($command, $input);
        try {
            $process->start();
        } catch (ProcessException $exception) {
            throw $this->logged(GitCommandFailed::couldNotRun($command, $exception->getMessage()));
        }
        $this->logger->debug('{command} started in {workingDirectory}.', [
            'command' => $command->describe(),
            'workingDirectory' => $command->workingDirectory,
        ]);

        return new GitProcess($command, $process, $input, $this->logger);
    }

    private function process(GitCommand $command, string|InputStream|null $input): Process
    {
        return new Process(
            ['git', ...$command->arguments],
            $command->workingDirectory,
            $this->environment($command),
            $input,
            $command->timeoutSeconds,
        );
    }

    /**
     * Process adds the inherited variables unless they are false.
     *
     * @return array<string, string|false>
     */
    private function environment(GitCommand $command): array
    {
        $environment = self::FIXED_ENVIRONMENT;
        $environment['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        $environment['GIT_CEILING_DIRECTORIES'] = \dirname($command->workingDirectory);
        $environment['GIT_CONFIG_COUNT'] = (string) \count($command->secrets);
        foreach ($command->secrets as $index => $secret) {
            $environment['GIT_CONFIG_KEY_'.$index] = $secret->key;
            $environment['GIT_CONFIG_VALUE_'.$index] = $secret->value;
        }

        $inheritedNames = array_filter(array_keys(getenv() + $_ENV), \is_string(...));

        return $environment + array_fill_keys($inheritedNames, false);
    }

    private function logged(GitCommandFailed $failure): GitCommandFailed
    {
        $this->logger->warning($failure->getMessage());

        return $failure;
    }
}
