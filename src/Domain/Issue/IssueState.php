<?php

declare(strict_types=1);

namespace App\Domain\Issue;

use App\Domain\Actor\ActorKind;

/**
 * What {@see IssueRules} reads of an issue.
 */
final readonly class IssueState
{
    /**
     * @param string|null $planRevision     The ID of the newest section_changed entry of the plan
     * @param string|null $reviewer         The reviewer of the plan approval
     * @param string|null $approvedRevision The plan revision that the plan approval names
     */
    public function __construct(
        public IssueStatus $status,
        public ?string $problem = null,
        public ?string $plan = null,
        public ?string $result = null,
        public ?string $planRevision = null,
        public ?ActorKind $approvedBy = null,
        public ?string $reviewer = null,
        public ?string $approvedRevision = null,
        public ?WaitingFor $waitingFor = null,
        public IssuePartyKind $requester = IssuePartyKind::User,
    ) {
    }

    /**
     * Without $waitingFor, the issue keeps its party in the status that it has, and a new status takes its default.
     */
    public function withStatus(IssueStatus $status, ?WaitingFor $waitingFor = null): self
    {
        return clone ($this, ['status' => $status, 'waitingFor' => $waitingFor ?? ($status === $this->status ? $this->waitingFor : WaitingFor::defaultFor($status))]);
    }
}
