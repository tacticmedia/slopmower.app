<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Request\Action;

/**
 * request.schema.json. `location` and `excerpt` are null.
 */
final readonly class RequestView
{
    /**
     * @param list<ThreadEntry>    $thread         In seq order
     * @param list<CommitLinkView> $commits        In linkedAt order
     * @param list<Action>         $allowedActions For the caller
     */
    public function __construct(
        public RequestSummaryView $summary,
        public array $thread,
        public array $commits,
        public array $allowedActions,
    ) {
    }
}
