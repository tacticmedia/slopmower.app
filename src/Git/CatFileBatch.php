<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\BlobSha;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * One running `git cat-file --batch` process. {@see GitReader} keeps one per clone, and each {@see StreamedBlob} has
 * its own.
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

        return new self($git->start($gitDir, ['cat-file', '--batch'], $input), $input);
    }

    /**
     * @return Blob|null Null when the object is missing or not a blob
     *
     * @throws GitFailed
     */
    public function blob(BlobSha $sha): ?Blob
    {
        [$type, $sizeBytes] = $this->open($sha) ?? [null, null];

        if (null === $sizeBytes) {
            return null;
        }

        // Also the bytes of another object type, so the next request reads its own header.
        $bytes = $this->take($sizeBytes, 1);

        return ObjectType::Blob === $type ? new Blob($sha, $bytes) : null;
    }

    /**
     * Requests an object and reads its header. The object bytes follow, then LF.
     *
     * @return array{ObjectType|null, int}|null The type and the size in bytes. Null when the object is missing.
     *
     * @throws GitFailed
     */
    public function open(BlobSha $sha): ?array
    {
        $this->input->write("{$sha}\n");

        while (false === $end = strpos($this->buffer, "\n")) {
            $this->fill(strlen($this->buffer) + 1);
        }

        $header = $this->take($end, 1);

        if (1 !== preg_match('/^[0-9a-f]{40,64} ([a-z]+) (\d+)$/D', $header, $match)) {
            return str_ends_with($header, ' missing') ? null : throw new GitFailed("git cat-file --batch returned an unexpected header: {$header}");
        }

        return [ObjectType::tryFrom($match[1]), (int) $match[2]];
    }

    /**
     * @throws GitFailed
     */
    public function peek(int $bytes): string
    {
        $this->fill($bytes);

        return substr($this->buffer, 0, $bytes);
    }

    /**
     * @return \Generator<string> The next $bytes as they arrive
     *
     * @throws GitFailed
     */
    public function chunks(int $bytes): \Generator
    {
        while ($bytes > 0) {
            $this->fill(1);
            $chunk = $this->take(min($bytes, strlen($this->buffer)));
            $bytes -= strlen($chunk);

            yield $chunk;
        }
    }

    public function stop(): void
    {
        $this->input->close();
        $this->process->stop(1);
    }

    /**
     * @throws GitFailed
     */
    private function take(int $bytes, int $delimiterBytes = 0): string
    {
        $this->fill($bytes + $delimiterBytes);
        $taken = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes + $delimiterBytes);

        return $taken;
    }

    /**
     * Reads until the buffer holds at least $bytes. Each call gets its own timeout.
     *
     * The Process iterator yields from the Process output buffer, so it also yields what other Process calls read.
     *
     * @throws GitFailed
     */
    private function fill(int $bytes): void
    {
        if (strlen($this->buffer) >= $bytes) {
            return;
        }

        $this->process->setTimeout(microtime(true) - $this->process->getStartTime() + self::READ_TIMEOUT_SECONDS);

        try {
            foreach ($this->process->getIterator(Process::ITER_SKIP_ERR) as $output) {
                $this->buffer .= $output;

                if (strlen($this->buffer) >= $bytes) {
                    return;
                }
            }
        } catch (ProcessTimedOutException) {
            throw new GitFailed('git cat-file --batch timed out after '.self::READ_TIMEOUT_SECONDS.' s');
        }

        throw new GitFailed('git cat-file --batch stopped: '.trim($this->process->getErrorOutput()));
    }
}
