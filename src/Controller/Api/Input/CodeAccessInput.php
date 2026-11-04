<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Repository\CodeAccess;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CodeAccessInput.
 */
final readonly class CodeAccessInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?CodeAccess $codeAccess = null,
    ) {
    }
}
