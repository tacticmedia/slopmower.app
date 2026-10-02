<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Anchor\AnchorKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * anchor.schema.json#/$defs/AnchorInput. {@see \App\Application\AnchorFactory} checks the kind rules and the file.
 * A snapshot in the body is an unknown property, so the serializer ignores it.
 */
final readonly class AnchorInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?AnchorKind $kind = null,
        #[Assert\NotNull]
        public ?string $path = null,
        #[Assert\Valid]
        public ?LineRangeInput $range = null,
        #[Assert\Valid]
        public ?SymbolInput $symbol = null,
    ) {
    }
}
