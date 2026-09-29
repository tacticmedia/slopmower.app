<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Action;
use App\Domain\Request\RequestStatus;

final class InvalidTransition extends DomainError
{
    /**
     * @var list<Action>
     */
    private readonly array $allowedActions;

    /**
     * @param list<Action> $allowedActions duplicates are dropped
     */
    public function __construct(
        private readonly RequestId $requestId,
        private readonly Action $action,
        private readonly RequestStatus $status,
        private readonly ?Actor $claimedBy,
        array $allowedActions,
    ) {
        $allowedActionsByValue = [];
        foreach ($allowedActions as $allowedAction) {
            $allowedActionsByValue[$allowedAction->value] ??= $allowedAction;
        }
        $this->allowedActions = array_values($allowedActionsByValue);

        $message = \sprintf('Request %s in status %s does not allow %s.', $requestId, $status->value, $action->value);
        if (null !== $claimedBy?->agentName) {
            $message .= \sprintf(' Agent %s holds its claim.', $claimedBy->agentName);
        }
        parent::__construct($message);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InvalidTransition;
    }

    /**
     * @return array{requestId: RequestId, action: Action, status: RequestStatus, claimedBy: ?Actor, allowedActions: list<Action>}
     */
    public function details(): array
    {
        return [
            'requestId' => $this->requestId,
            'action' => $this->action,
            'status' => $this->status,
            'claimedBy' => $this->claimedBy,
            'allowedActions' => $this->allowedActions,
        ];
    }
}
