<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Request\PullRequestUrl;

/**
 * link-pull-request-result.schema.json.
 */
final readonly class LinkPullRequestResult
{
    /**
     * @param list<RequestSummaryView> $requests Every request that the command named, in its order
     * @param list<EventView>          $events   The events that the command wrote
     */
    public function __construct(
        public PullRequestUrl $url,
        public array $requests,
        public array $events,
    ) {
    }
}
