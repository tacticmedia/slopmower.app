<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Domain\Identity\TenantId;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TenantStamp implements StampInterface
{
    public function __construct(public TenantId $tenantId)
    {
    }
}
