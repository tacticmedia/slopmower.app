<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Review\IndexState;
use App\Entity\Review;

/**
 * review.schema.json#/$defs/IndexStatus: V5, and `index` of the Review.
 */
final readonly class IndexStatus
{
    private function __construct(
        public IndexState $state,
        public ?int $filesTotal,
        public int $filesDone,
        public int $symbolsTotal,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?string $error,
    ) {
    }

    public static function of(Review $review): self
    {
        return new self(
            $review->getIndexState(),
            $review->getFilesTotal(),
            $review->getFilesDone(),
            $review->getSymbolsTotal(),
            $review->getIndexStartedAt(),
            $review->getIndexFinishedAt(),
            $review->getIndexError(),
        );
    }
}
