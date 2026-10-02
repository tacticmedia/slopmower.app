<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * anchor.schema.json#/$defs/LineRange. {@see \App\Domain\Anchor\LineRange} checks the values.
 */
final readonly class LineRangeInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?int $startLine = null,
        #[Assert\NotNull]
        public ?int $endLine = null,
    ) {
    }
}
