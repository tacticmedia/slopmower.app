<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\ReviewId;
use App\Domain\Review\ReviewState;

/**
 * updates-page.schema.json.
 */
final readonly class UpdatesPage
{
    /**
     * @param list<UpdatedRequest> $requests
     * @param list<EventView>      $reviewEvents In seq order
     */
    public function __construct(
        public ReviewId $reviewId,
        public ReviewState $reviewState,
        public int $cursor,
        public bool $hasMore,
        public int $awaitingTotal,
        public array $requests,
        public array $reviewEvents,
    ) {
    }
}
