<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\BlobSha;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * One blob from its own `git cat-file --batch` process. The open reads the header and the bytes of the binary check,
 * {@see chunks()} reads the rest, so a blob of any size streams without being held in memory.
 */
final class StreamedBlob
{
    private const float READ_TIMEOUT_SECONDS = 60.0;

    public private(set) int $sizeBytes = 0;

    private string $buffer = '';

    /** Whether the buffer holds the current value of the output generator. */
    private bool $outputTaken = false;

    /**
     * @param \Generator<string> $output
     */
    private function __construct(
        private readonly Process $process,
        private readonly \Generator $output,
        public readonly BlobSha $sha,
    ) {
    }

    /**
     * @return self|null Null when the object is missing or not a blob
     *
     * @throws GitFailed
     */
    public static function open(GitRunner $git, string $gitDir, BlobSha $blob): ?self
    {
        $input = new InputStream();
        $process = $git->start($gitDir, ['cat-file', '--batch'], $input);
        $input->write("{$blob}\n");
        $input->close();
        $stream = new self($process, $process->getIterator(Process::ITER_SKIP_ERR), $blob);

        try {
            $header = $stream->line();
            $isObject = 1 === preg_match('/^[0-9a-f]{40,64} ([a-z]+) (\d+)$/D', $header, $match);

            if (!$isObject && !str_ends_with($header, ' missing')) {
                throw new GitFailed("git cat-file --batch returned an unexpected header: {$header}");
            }

            if (!$isObject || ObjectType::Blob !== ObjectType::tryFrom($match[1])) {
                $stream->close();

                return null;
            }

            $stream->sizeBytes = (int) $match[2];
            $stream->fill(min($stream->sizeBytes, Blob::BINARY_CHECK_BYTES));
        } catch (GitFailed $failure) {
            $stream->close();

            throw $failure;
        }

        return $stream;
    }

    public function isBinary(): bool
    {
        return new Blob($this->sha, substr($this->buffer, 0, min($this->sizeBytes, Blob::BINARY_CHECK_BYTES)))->isBinary();
    }

    /**
     * Stops the process after the last byte or when the consumer stops early.
     *
     * @return \Generator<string>
     *
     * @throws GitFailed
     */
    public function chunks(): \Generator
    {
        try {
            for ($remainingBytes = $this->sizeBytes; $remainingBytes > 0; $remainingBytes -= strlen($chunk)) {
                if ('' === $this->buffer) {
                    $this->fill(1);
                }

                $chunk = substr($this->buffer, 0, $remainingBytes);
                $this->buffer = substr($this->buffer, strlen($chunk));

                yield $chunk;
            }
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->process->stop(1);
    }

    /**
     * @throws GitFailed
     */
    private function line(): string
    {
        while (false === $end = strpos($this->buffer, "\n")) {
            $this->fill(strlen($this->buffer) + 1);
        }

        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);

        return $line;
    }

    /**
     * Reads until the buffer holds at least $bytes. Each read gets its own timeout.
     *
     * @throws GitFailed
     */
    private function fill(int $bytes): void
    {
        while (strlen($this->buffer) < $bytes) {
            $this->process->setTimeout(microtime(true) - $this->process->getStartTime() + self::READ_TIMEOUT_SECONDS);

            try {
                if ($this->outputTaken) {
                    $this->output->next();
                }

                if (!$this->output->valid()) {
                    throw new GitFailed('git cat-file --batch stopped: '.trim($this->process->getErrorOutput()));
                }
            } catch (ProcessTimedOutException) {
                throw new GitFailed('git cat-file --batch timed out after '.self::READ_TIMEOUT_SECONDS.' s');
            }

            $this->buffer .= $this->output->current();
            $this->outputTaken = true;
        }
    }
}
