<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\BlobSha;

/**
 * One blob that streams without being held in memory: from a {@see CatFileBatch} of its own, or from a GitHub response.
 */
final readonly class StreamedBlob
{
    /**
     * @param int|null                     $sizeBytes Null when the source does not tell it
     * @param \Closure(): iterable<string> $chunks    Every byte of the blob
     * @param \Closure(): void             $close     Stops the source
     */
    public function __construct(public ?int $sizeBytes, private bool $binary, private \Closure $chunks, private \Closure $close)
    {
    }

    /**
     * The open reads the header and the bytes of the binary check, {@see chunks()} reads the rest.
     *
     * @return self|null Null when the object is missing or not a blob
     *
     * @throws GitFailed
     */
    public static function open(GitRunner $git, string $gitDir, BlobSha $blob): ?self
    {
        $batch = CatFileBatch::start($git, $gitDir);
        $stream = null;

        try {
            [$type, $sizeBytes] = $batch->open($blob) ?? [null, 0];
            $stream = ObjectType::Blob === $type ? new self(
                $sizeBytes,
                new Blob($blob, $batch->peek(min($sizeBytes, Blob::BINARY_CHECK_BYTES)))->isBinary(),
                static fn (): \Generator => $batch->chunks($sizeBytes),
                $batch->stop(...),
            ) : null;
        } finally {
            if (null === $stream) {
                $batch->stop();
            }
        }

        return $stream;
    }

    public function isBinary(): bool
    {
        return $this->binary;
    }

    /**
     * Stops the source after the last byte or when the consumer stops early.
     *
     * @return \Generator<string>
     *
     * @throws GitFailed
     */
    public function chunks(): \Generator
    {
        try {
            yield from ($this->chunks)();
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        ($this->close)();
    }
}
