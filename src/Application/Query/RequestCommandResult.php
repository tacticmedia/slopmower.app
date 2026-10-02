<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Request\Action;

/**
 * request-command-result.schema.json.
 */
final readonly class RequestCommandResult
{
    /**
     * @param list<Action>    $allowedActions For the caller
     * @param list<EventView> $events         The events that the command wrote
     */
    public function __construct(
        public RequestSummaryView $request,
        public array $allowedActions,
        public array $events,
    ) {
    }
}
