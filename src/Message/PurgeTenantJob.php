<?php

declare(strict_types=1);

namespace App\Message;

use App\Application\PurgeTenant;
use App\Domain\Identity\TenantId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A2. Handled by {@see PurgeTenant::handle()}. A repeated run deletes what is left.
 */
#[AsMessage('async')]
final readonly class PurgeTenantJob
{
    public function __construct(public TenantId $tenantId)
    {
    }
}
