<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Index\ExtractedSymbol;
use App\Index\SymbolTable;

/**
 * lists.schema.json#/$defs/SymbolList: V9.
 */
final readonly class SymbolList
{
    /**
     * @param list<ExtractedSymbol> $items
     */
    private function __construct(
        public ReviewId $reviewId,
        public RepoPath $path,
        public BlobSha $blobSha,
        public ?string $language,
        public bool $parseError,
        public array $items,
    ) {
    }

    public static function of(ReviewId $reviewId, RepoPath $path, BlobSha $blobSha, SymbolTable $table): self
    {
        return new self($reviewId, $path, $blobSha, $table->language, $table->parseError, $table->symbols);
    }
}
