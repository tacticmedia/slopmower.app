<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Entity\Review;

final readonly class ReviewView
{
    /**
     * @param array<string, int> $requestsByStatus   Every RequestStatus value
     * @param array<string, int> $requestsByAwaiting Every Awaiting value
     */
    public function __construct(
        public Review $review,
        public array $requestsByStatus,
        public array $requestsByAwaiting,
        public int $outdatedRequests,
    ) {
    }
}
