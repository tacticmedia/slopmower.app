<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Review\Delivery;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/SubmitDraftsInput.
 */
final readonly class SubmitDraftsInput
{
    public function __construct(
        #[Assert\Length(max: 65536)]
        public ?string $summary = null,
        public ?Delivery $delivery = null,
    ) {
    }
}
