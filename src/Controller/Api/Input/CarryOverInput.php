<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CarryOverInput.
 */
final readonly class CarryOverInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Ulid]
        public ?string $fromReviewId = null,
    ) {
    }
}
