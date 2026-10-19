<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Entity\Tour;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/PreferencesInput. The Serializer refuses an unknown name.
 */
final readonly class PreferencesInput
{
    /**
     * @param list<Tour>|null $completedTours Null keeps the stored list
     */
    public function __construct(
        #[Assert\Unique]
        public ?array $completedTours = null,
    ) {
    }
}
