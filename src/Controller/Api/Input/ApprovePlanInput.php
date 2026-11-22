<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/ApprovePlanInput.
 */
final readonly class ApprovePlanInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Ulid]
        public ?string $planRevision = null,
    ) {
    }
}
