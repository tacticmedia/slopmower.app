<?php

declare(strict_types=1);

namespace App\Git;

final readonly class BlobContent
{
    // The rule of git and of docs/specs R7: a NUL byte in the first 8,000 bytes.
    private const int BINARY_CHECK_BYTES = 8000;

    public function __construct(public string $bytes)
    {
    }

    public function sizeBytes(): int
    {
        return \strlen($this->bytes);
    }

    public function isBinary(): bool
    {
        return str_contains(substr($this->bytes, 0, self::BINARY_CHECK_BYTES), "\0");
    }

    /**
     * Lines end at LF, so a final LF does not start another line, as in {@see \App\Domain\Anchor\SnapshotCapture::fileLines()}.
     */
    public function lineCount(): int
    {
        if ('' === $this->bytes) {
            return 0;
        }

        return substr_count($this->bytes, "\n") + (str_ends_with($this->bytes, "\n") ? 0 : 1);
    }
}
