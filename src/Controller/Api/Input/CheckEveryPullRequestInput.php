<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CheckEveryPullRequestInput.
 */
final readonly class CheckEveryPullRequestInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?bool $enabled = null,
    ) {
    }
}
