<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/OpenRequestInput.
 */
final readonly class OpenRequestInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?AnchorInput $anchor = null,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
    ) {
    }
}
