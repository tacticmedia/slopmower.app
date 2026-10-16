<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/TitleInput.
 */
final readonly class TitleInput
{
    public function __construct(
        #[Assert\NotNull]
        #[RequestTitle]
        public ?string $title = null,
    ) {
    }
}
