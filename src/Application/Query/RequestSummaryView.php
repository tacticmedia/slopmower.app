<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Request\RequestState;
use App\Entity\ChangeRequest;

/**
 * request-summary.schema.json. The state carries the links, for commitCount and pendingCommitCount.
 */
final readonly class RequestSummaryView
{
    public function __construct(
        public ChangeRequest $request,
        public RequestState $state,
        public int $commentCount,
    ) {
    }
}
