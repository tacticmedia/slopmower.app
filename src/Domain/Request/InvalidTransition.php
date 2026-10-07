<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Identity\RequestId;

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
        ?string $reason = null,
    ) {
        parent::__construct("The request is {$status->value}, so {$action->value} is not allowed".(null === $reason ? '.' : ": {$reason}."));
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
