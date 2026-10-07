<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\ReviewId;
use App\Index\FileHit;
use App\Index\SymbolHit;

/**
 * lists.schema.json#/$defs/SearchHitList: V16.
 */
final readonly class SearchHitList
{
    /**
     * @param list<FileHit|SymbolHit> $items
     */
    public function __construct(
        public ReviewId $reviewId,
        public bool $partial,
        public array $items,
    ) {
    }
}
