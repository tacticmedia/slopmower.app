<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/DismissSuggestionInput.
 */
final readonly class DismissSuggestionInput
{
    public function __construct(
        #[Assert\Length(max: 65536)]
        public ?string $note = null,
    ) {
    }
}
