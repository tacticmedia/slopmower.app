<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\ApprovalMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/UpdateIssueInput. {@see \App\Application\IssueCommands::update()} refuses a body without a field.
 */
final readonly class UpdateIssueInput
{
    public function __construct(
        #[RequestTitle]
        public ?string $title = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $problem = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $plan = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $result = null,
        #[Assert\Length(min: 1, max: 65536)]
        public ?string $followUps = null,
        public ?ApprovalMode $approval = null,
        #[Assert\Length(min: 1, max: 64)]
        public ?string $session = null,
    ) {
    }
}
