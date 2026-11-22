<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class ProjectPage
{
    /**
     * @param list<ProjectView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
