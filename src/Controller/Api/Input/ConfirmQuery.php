<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The `confirm` of R6 and A2. The controller compares it with the name it confirms.
 */
final readonly class ConfirmQuery implements QueryInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?string $confirm = null,
    ) {
    }
}
