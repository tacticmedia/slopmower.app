<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/WontDoInput: 20 or more characters after trimming.
 */
final readonly class WontDoInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(min: 20, normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $reason = null,
    ) {
    }
}
