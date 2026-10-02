<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/StartReviewInput. The ref rules are those of {@see \App\Domain\Git\RefName}.
 */
final readonly class StartReviewInput
{
    public function __construct(
        public ?string $ref = null,
        #[Assert\Length(min: 1, max: 200)]
        public ?string $title = null,
    ) {
    }
}
