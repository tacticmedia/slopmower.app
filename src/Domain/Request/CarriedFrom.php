<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;

/**
 * The source request of a request that V15 carried over from another review.
 */
final readonly class CarriedFrom
{
    public function __construct(
        public ReviewId $reviewId,
        public RequestId $requestId,
    ) {
    }
}
