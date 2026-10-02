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
     * @param list<Action> $allowedActions
     */
    public function __construct(
        private readonly RequestId $requestId,
        private readonly Action $action,
        private readonly RequestStatus $status,
        private readonly ?Actor $claimedBy,
        private readonly array $allowedActions,
    ) {
        parent::__construct("The request is {$status->value}, so {$action->value} is not allowed.");
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
