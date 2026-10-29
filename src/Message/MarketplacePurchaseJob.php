<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A `marketplace_purchase` delivery. The handler reads the account, because redeliveries arrive in any order (D97). Its
 * record belongs to no tenant, so the job has no TenantStamp.
 */
#[AsMessage('async')]
final readonly class MarketplacePurchaseJob
{
    public function __construct(public int $accountId)
    {
    }
}
