<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\ReviewId;

/**
 * carry-over-result.schema.json.
 */
final readonly class CarryOverResult
{
    /**
     * @param list<CarryOverItem> $items Sorted by the source request ID
     */
    public function __construct(
        public ReviewId $reviewId,
        public ReviewId $fromReviewId,
        public array $items,
    ) {
    }
}
