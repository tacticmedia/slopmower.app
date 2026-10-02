<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The `symbol` of anchor.schema.json#/$defs/AnchorInput.
 */
final readonly class SymbolInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(min: 1, max: 1024)]
        public ?string $fqn = null,
    ) {
    }
}
