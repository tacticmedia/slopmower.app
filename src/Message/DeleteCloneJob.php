<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Also deletes the index and the location cache. Carries the tenant ID, so it works after the repository row is
 * deleted. A repeated run finds nothing to delete.
 */
#[AsMessage('async')]
final readonly class DeleteCloneJob
{
    public function __construct(
        public TenantId $tenantId,
        public RepositoryId $repositoryId,
    ) {
    }
}
