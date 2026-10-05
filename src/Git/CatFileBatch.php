<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\BlobSha;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * One running `git cat-file --batch -Z` process. -Z delimits names and records with NUL.
 */
final class CatFileBatch
{
    private const float READ_TIMEOUT_SECONDS = 60.0;

    private string $buffer = '';

    private function __construct(private readonly Process $process, private readonly InputStream $input)
    {
    }

    public static function start(GitRunner $git, string $gitDir): self
    {
        $input = new InputStream();

        return new self($git->start($gitDir, ['cat-file', '--batch', '-Z'], $input), $input);
    }

    /**
     * @return Blob|null Null when the object is missing or not a blob
     *
     * @throws GitFailed
     */
    public function blob(BlobSha $sha): ?Blob
    {
        $this->input->write($sha->toString()."\0");
        $header = $this->read(fn (): int|false => strpos($this->buffer, "\0"));

        if (1 !== preg_match('/^([0-9a-f]{40}|[0-9a-f]{64}) ([a-z]+) (\d+)$/D', $header, $match)) {
            if (str_ends_with($header, ' missing')) {
                return null;
            }

            throw new GitFailed("git cat-file --batch returned an unexpected header: {$header}");
        }

        $sizeBytes = (int) $match[3];
        $bytes = $this->read(fn (): int|false => strlen($this->buffer) > $sizeBytes ? $sizeBytes : false);

        return ObjectType::Blob === ObjectType::tryFrom($match[2]) ? new Blob(BlobSha::fromString($match[1]), $bytes) : null;
    }

    public function stop(): void
    {
        $this->input->close();
        $this->process->stop(1);
    }

    /**
     * Waits until $length() returns the length of the next record, then takes the record and its NUL.
     *
     * The Process iterator yields from the Process output buffer, so it also yields what other Process calls read.
     *
     * @param \Closure(): (int|false) $length
     */
    private function read(\Closure $length): string
    {
        $recordLength = $length();

        if (false === $recordLength) {
            $this->process->setTimeout(microtime(true) - $this->process->getStartTime() + self::READ_TIMEOUT_SECONDS);

            try {
                foreach ($this->process->getIterator(Process::ITER_SKIP_ERR) as $output) {
                    $this->buffer .= $output;

                    if (false !== $recordLength = $length()) {
                        break;
                    }
                }
            } catch (ProcessTimedOutException) {
                throw new GitFailed('git cat-file --batch timed out after '.self::READ_TIMEOUT_SECONDS.' s');
            }

            if (false === $recordLength) {
                throw new GitFailed('git cat-file --batch stopped: '.trim($this->process->getErrorOutput()));
            }
        }

        $record = substr($this->buffer, 0, $recordLength);
        $this->buffer = substr($this->buffer, $recordLength + 1);

        return $record;
    }
}
