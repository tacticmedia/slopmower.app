<?php

declare(strict_types=1);

namespace App\Tests\Support\Git;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;

final readonly class CloneLocation
{
    public function __construct(
        public TenantId $tenantId,
        public RepositoryId $repositoryId,
        public string $directory,
    ) {
    }
}
