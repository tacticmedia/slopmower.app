<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * updates-page.schema.json#/$defs/UpdatedRequest.
 */
final readonly class UpdatedRequest
{
    /**
     * @param list<EventView> $newEvents In seq order
     */
    public function __construct(
        public RequestSummaryView $request,
        public array $newEvents,
        public int $omittedEvents,
    ) {
    }
}
