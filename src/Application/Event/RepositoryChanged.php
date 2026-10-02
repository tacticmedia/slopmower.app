<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;

/**
 * Dispatched after a change to a repository commits, for the `repository` live update of
 * docs/specs/webhooks-and-live-updates.md section 3.
 */
final readonly class RepositoryChanged
{
    public function __construct(
        public TenantId $tenantId,
        public RepositoryId $repositoryId,
    ) {
    }
}
