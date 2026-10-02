<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\CommitSha;
use App\Domain\Request\CommitLinkState;

/**
 * link-commits-result.schema.json.
 */
final readonly class LinkCommitsResult
{
    /**
     * @param list<RequestSummaryView> $requests Every request that the command named, in its order
     * @param list<EventView>          $events   The events that the command wrote
     */
    public function __construct(
        public CommitSha $commitSha,
        public CommitLinkState $state,
        public array $requests,
        public array $events,
    ) {
    }
}
