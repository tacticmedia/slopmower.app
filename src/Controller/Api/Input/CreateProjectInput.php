<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueKey;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CreateProjectInput.
 */
final readonly class CreateProjectInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Regex(pattern: IssueKey::PROJECT_KEY_PATTERN, message: 'Must have 2 to 10 upper-case letters.')]
        public ?string $key = null,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $name = null,
        #[Assert\NotNull]
        public ?ApprovalMode $approvalMode = null,
        #[Assert\Length(max: 10000)]
        public ?string $approvalGuidelines = null,
    ) {
    }
}
