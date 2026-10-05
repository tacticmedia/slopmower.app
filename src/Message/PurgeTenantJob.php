<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\TenantId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A2. A repeated run deletes what is left.
 */
#[AsMessage('async')]
final readonly class PurgeTenantJob
{
    public function __construct(public TenantId $tenantId)
    {
    }
}
