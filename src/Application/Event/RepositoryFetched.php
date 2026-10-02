<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;

/**
 * Dispatched after the row of a successful clone or fetch commits and the repository lock is released, so a listener
 * can write through the CommandExecutor.
 */
final readonly class RepositoryFetched
{
    public function __construct(
        public TenantId $tenantId,
        public RepositoryId $repositoryId,
        public ?CommitSha $previousDefaultBranchHead,
        public ?CommitSha $defaultBranchHead,
    ) {
    }
}
