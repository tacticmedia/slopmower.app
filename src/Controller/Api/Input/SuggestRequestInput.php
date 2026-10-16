<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/SuggestRequestInput.
 */
final readonly class SuggestRequestInput
{
    /**
     * @param AnchorInput|null $anchor Absent or null for a general request
     */
    public function __construct(
        #[Assert\Valid]
        public ?AnchorInput $anchor = null,
        #[Assert\NotNull]
        #[RequestTitle]
        public ?string $title = null,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
    ) {
    }
}
