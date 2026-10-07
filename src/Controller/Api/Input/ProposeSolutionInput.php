<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/ProposeSolutionInput. The decider checks the diff format.
 */
final readonly class ProposeSolutionInput
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $plan = null,
        #[Assert\Length(max: 65536)]
        public ?string $diff = null,
    ) {
    }
}
