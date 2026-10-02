<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class ReviewPage
{
    /**
     * @param list<ReviewView> $items
     */
    public function __construct(
        public RepositoryView $repository,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
