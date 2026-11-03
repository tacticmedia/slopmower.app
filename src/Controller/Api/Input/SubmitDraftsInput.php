<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Review\Delivery;
use App\Entity\Draft;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/SubmitDraftsInput. IDs are case-insensitive, so they are unique in upper case.
 */
final readonly class SubmitDraftsInput
{
    /**
     * @param list<string>|null $draftIds
     */
    public function __construct(
        #[Assert\Length(max: 65536)]
        public ?string $summary = null,
        public ?Delivery $delivery = null,
        #[Assert\Count(min: 1, max: Draft::MAX_PER_USER_AND_REVIEW)]
        #[Assert\Unique(normalizer: 'strtoupper')]
        #[Assert\All([new Assert\NotNull(), new Assert\Ulid()])]
        public ?array $draftIds = null,
    ) {
    }
}
