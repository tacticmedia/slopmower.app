<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueClaim;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueStatus;
use App\Entity\Issue;

/**
 * issue.schema.json#/$defs/IssueSummary.
 */
final readonly class IssueSummaryView
{
    public function __construct(
        public IssueKey $key,
        public string $title,
        public IssueCategory $category,
        public IssueStatus $status,
        public ApprovalMode $approval,
        public ?IssueClaim $claim,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(Issue $issue, \DateTimeImmutable $now): self
    {
        return new self($issue->getKey(), $issue->getTitle(), $issue->getCategory(), $issue->getStatus(), $issue->getApprovalMode(), IssueClaim::held($issue->getClaim(), $now), $issue->getUpdatedAt());
    }
}
