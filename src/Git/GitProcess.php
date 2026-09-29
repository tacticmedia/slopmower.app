<?php

declare(strict_types=1);

namespace App\Git;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * A long-lived git process from {@see GitRunner::start()}. Destroying it stops git.
 */
final class GitProcess
{
    private string $unreadOutput = '';

    public function __construct(
        private readonly GitCommand $command,
        private readonly Process $process,
        private readonly InputStream $input,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Writes the request to stdin, then reads stdout until the response is complete.
     *
     * @param \Closure(string): ?int $responseLength gets the output read so far, returns the byte length of
     *                                               the whole response once the output shows it, else null
     *
     * @throws GitCommandFailed when git exits, or the response takes longer than the timeout of the command
     */
    public function request(string $request, \Closure $responseLength): string
    {
        if ($this->input->isClosed()) {
            throw new \LogicException(\sprintf('%s is closed.', $this->command->describe()));
        }
        if ('' !== $this->unreadOutput) {
            throw new \LogicException(\sprintf('%s returned more output than the previous response.', $this->command->describe()));
        }

        $this->input->write($request);
        $this->allowTimeoutFromNow();
        try {
            // Unlike waitUntil(), the iterator ends when git exits, although stdin stays open.
            foreach ($this->process->getIterator(Process::ITER_SKIP_ERR) as $output) {
                $this->unreadOutput .= $output;
                $length = $responseLength($this->unreadOutput);
                if (null !== $length && $length <= \strlen($this->unreadOutput)) {
                    $response = substr($this->unreadOutput, 0, $length);
                    $this->unreadOutput = substr($this->unreadOutput, $length);

                    return $response;
                }
            }

            throw $this->logged(GitCommandFailed::exited($this->command, $this->process->wait(), $this->process->getErrorOutput()));
        } catch (ProcessTimedOutException) {
            throw $this->logged(GitCommandFailed::timedOut($this->command, $this->process->getErrorOutput()));
        } catch (ProcessException $exception) {
            throw $this->logged(GitCommandFailed::couldNotRun($this->command, $exception->getMessage()));
        }
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Closes stdin and waits for git to exit. Git that exceeds the timeout of the command is stopped.
     */
    public function close(): void
    {
        if ($this->input->isClosed()) {
            return;
        }

        $this->input->close();
        $this->allowTimeoutFromNow();
        try {
            $exitCode = $this->process->wait();
        } catch (ProcessException $exception) {
            $this->logger->warning($this->command->redact(\sprintf('%s did not exit cleanly: %s', $this->command->describe(), $exception->getMessage())));

            return;
        }
        $this->logger->debug('{command} exited with code {exitCode} in {workingDirectory}.', [
            'command' => $this->command->describe(),
            'exitCode' => $exitCode,
            'workingDirectory' => $this->command->workingDirectory,
            'errorOutput' => $this->command->redact($this->process->getErrorOutput()),
        ]);
    }

    /**
     * Process measures its timeout from the start of git, so each request moves it.
     */
    private function allowTimeoutFromNow(): void
    {
        $this->process->setTimeout(microtime(true) - $this->process->getStartTime() + $this->command->timeoutSeconds);
    }

    private function logged(GitCommandFailed $failure): GitCommandFailed
    {
        $this->logger->warning($failure->getMessage());

        return $failure;
    }
}
