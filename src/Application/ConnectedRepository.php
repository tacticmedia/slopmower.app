<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Repository;

final readonly class ConnectedRepository
{
    /**
     * @param bool $created False when the source was connected already
     */
    public function __construct(
        public Repository $repository,
        public bool $created,
    ) {
    }
}
