<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class RepositoryPage
{
    /**
     * @param list<RepositoryView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
