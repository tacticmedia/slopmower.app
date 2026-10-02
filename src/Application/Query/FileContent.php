<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Git\Blob;

/**
 * The rules of V7 in docs/specs/rest-api.md section 5. The normalizer adds `language` and `rawUrl`.
 */
final readonly class FileContent
{
    public const int MAX_RENDERABLE_BYTES = 1_048_576;

    /**
     * @param Blob|null $blob Null when the blob is too large to hold
     */
    public function __construct(
        public ReviewId $reviewId,
        public CommitSha $commitSha,
        public RepoPath $path,
        public BlobSha $blobSha,
        public string $mode,
        public bool $symlink,
        public int $sizeBytes,
        public bool $binary,
        private ?Blob $blob,
    ) {
    }

    public function isTooLarge(): bool
    {
        return $this->sizeBytes > self::MAX_RENDERABLE_BYTES;
    }

    public function isRenderable(): bool
    {
        return !$this->symlink && !$this->binary && !$this->isTooLarge();
    }

    public function text(): ?string
    {
        return $this->isRenderable() ? $this->blob?->bytes : null;
    }

    public function lineCount(): ?int
    {
        return $this->isRenderable() ? $this->blob?->lineCount() : null;
    }

    /**
     * Null also for a target that is not valid UTF-8, which JSON cannot carry.
     */
    public function symlinkTarget(): ?string
    {
        $target = $this->symlink ? $this->blob?->bytes : null;

        return null !== $target && mb_check_encoding($target, 'UTF-8') ? $target : null;
    }
}
