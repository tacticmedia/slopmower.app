<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\ReviewId;
use App\Domain\Review\ReviewState;

/**
 * lists.schema.json#/$defs/RequestPage.
 */
final readonly class RequestPage
{
    /**
     * @param list<RequestSummaryView> $items
     */
    public function __construct(
        public ReviewId $reviewId,
        public ReviewState $reviewState,
        public int $total,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
