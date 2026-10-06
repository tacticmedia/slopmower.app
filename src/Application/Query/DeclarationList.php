<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\ReviewId;
use App\Index\Declaration;

/**
 * lists.schema.json#/$defs/DeclarationList: V14.
 */
final readonly class DeclarationList
{
    /**
     * @param list<Declaration> $items
     */
    public function __construct(
        public ReviewId $reviewId,
        public array $items,
    ) {
    }
}
