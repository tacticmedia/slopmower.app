<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssuePartyKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/UpdateIssueInput. {@see \App\Application\IssueCommands::update()} refuses a body without a field.
 */
final readonly class UpdateIssueInput
{
    public function __construct(
        #[RequestTitle]
        public ?string $title = null,
        public ?IssueCategory $category = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $problem = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $acceptanceCriteria = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $plan = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $result = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $followUps = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $notes = null,
        public ?ApprovalMode $approval = null,
        public ?IssuePartyKind $requester = null,
        public ?IssuePartyKind $assignee = null,
        #[Assert\Length(min: 1, max: 64)]
        public ?string $session = null,
    ) {
    }
}
