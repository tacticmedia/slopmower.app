<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Actor\Actor;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueClaim;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueParty;
use App\Domain\Issue\IssueStatus;
use App\Domain\Issue\WaitingFor;

/**
 * issue.schema.json.
 */
final readonly class IssueView
{
    /**
     * @param array{key: string, name: string, approvalMode: ApprovalMode}                                                  $project
     * @param string|null                                                                                                   $planRevision    The ID of the newest section_changed entry of the plan
     * @param array{approvedBy: Actor, approvedAt: \DateTimeImmutable, reviewer: string|null, reviewNote: string|null}|null $planApproval
     * @param list<IssueStatus>                                                                                             $allowedStatuses
     */
    public function __construct(
        public IssueKey $key,
        public string $title,
        public IssueCategory $category,
        public IssueStatus $status,
        public ApprovalMode $approval,
        public ?IssueClaim $claim,
        public IssueParty $requester,
        public IssueParty $assignee,
        public ?WaitingFor $waitingFor,
        public ?string $problem,
        public ?string $acceptanceCriteria,
        public ?string $plan,
        public ?string $result,
        public ?string $followUps,
        public ?string $notes,
        public array $project,
        public ?string $planRevision,
        public ?array $planApproval,
        public array $allowedStatuses,
        public Actor $createdBy,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $lastActivityAt,
    ) {
    }
}
