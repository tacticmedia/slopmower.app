<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RequestId;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\OrphanReason;
use App\Domain\Request\RequestStatus;

/**
 * carry-over-result.schema.json#/$defs/CarryOverItem.
 */
final readonly class CarryOverItem
{
    public function __construct(
        public RequestSummaryView $source,
        public CarryOverOutcome $outcome,
        public ?RequestId $requestId,
        public ?LocationStatus $location,
        public RequestStatus|OrphanReason|null $reason,
    ) {
    }
}
