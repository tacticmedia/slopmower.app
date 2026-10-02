<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Entity\Repository;

final readonly class RepositoryView
{
    public function __construct(
        public Repository $repository,
        public int $openReviewCount,
    ) {
    }
}
