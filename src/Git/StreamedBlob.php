<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\BlobSha;

/**
 * One blob from a {@see CatFileBatch} of its own. The open reads the header and the bytes of the binary check,
 * {@see chunks()} reads the rest, so a blob of any size streams without being held in memory.
 */
final readonly class StreamedBlob
{
    private function __construct(private CatFileBatch $batch, public int $sizeBytes, private bool $binary)
    {
    }

    /**
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
            $stream = ObjectType::Blob === $type ? new self($batch, $sizeBytes, new Blob($blob, $batch->peek(min($sizeBytes, Blob::BINARY_CHECK_BYTES)))->isBinary()) : null;
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
     * Stops the process after the last byte or when the consumer stops early.
     *
     * @return \Generator<string>
     *
     * @throws GitFailed
     */
    public function chunks(): \Generator
    {
        try {
            yield from $this->batch->chunks($this->sizeBytes);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->batch->stop();
    }
}
