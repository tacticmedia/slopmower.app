<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssuePriority;
use App\Domain\Issue\IssueStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CreateIssueInput. {@see \App\Domain\Issue\IssueRules} refuses a status after todo.
 */
final readonly class CreateIssueInput
{
    public function __construct(
        #[Assert\NotNull]
        #[RequestTitle]
        public ?string $title = null,
        #[Assert\NotNull]
        public ?IssueCategory $category = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $problem = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $acceptanceCriteria = null,
        public ?IssueStatus $status = null,
        public ?ApprovalMode $approval = null,
        public ?IssuePartyKind $requester = null,
        public ?IssuePartyKind $assignee = null,
        public ?IssuePriority $priority = null,
    ) {
    }
}
