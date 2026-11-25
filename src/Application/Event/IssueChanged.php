<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Identity\TenantId;
use App\Domain\Issue\IssueKey;

/**
 * Dispatched after a write of an issue commits, for the `issue` live update of
 * docs/specs/webhooks-and-live-updates.md section 3.
 */
final readonly class IssueChanged
{
    public function __construct(
        public TenantId $tenantId,
        public IssueKey $issueKey,
    ) {
    }
}
