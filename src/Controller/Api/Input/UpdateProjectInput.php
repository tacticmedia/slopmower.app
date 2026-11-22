<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\ApprovalMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/UpdateProjectInput.
 */
final readonly class UpdateProjectInput
{
    /**
     * @param list<string>|null $repositoryIds
     */
    public function __construct(
        #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $name = null,
        public ?ApprovalMode $approvalMode = null,
        #[Assert\Count(max: 500)]
        #[Assert\All([new Assert\NotNull(), new Assert\Ulid()])]
        public ?array $repositoryIds = null,
    ) {
    }
}
