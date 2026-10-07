<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/PlanRequirementInput.
 */
final readonly class PlanRequirementInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?bool $requireApproval = null,
    ) {
    }
}
