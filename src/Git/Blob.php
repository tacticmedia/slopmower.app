<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\BlobSha;

final readonly class Blob
{
    /** Git's own binary check looks at the same number of bytes. */
    public const int BINARY_CHECK_BYTES = 8000;

    public function __construct(public BlobSha $sha, public string $bytes)
    {
    }

    public function isBinary(): bool
    {
        return str_contains(substr($this->bytes, 0, self::BINARY_CHECK_BYTES), "\0");
    }

    /**
     * Lines end at LF. A final line without LF counts.
     */
    public function lineCount(): int
    {
        return substr_count($this->bytes, "\n") + ('' === $this->bytes || str_ends_with($this->bytes, "\n") ? 0 : 1);
    }
}
