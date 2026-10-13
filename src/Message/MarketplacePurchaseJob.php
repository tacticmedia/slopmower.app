<?php

declare(strict_types=1);

namespace App\Message;

use App\GitHub\Payload\MarketplacePurchaseAction;
use App\GitHub\Payload\MarketplacePurchasePayload;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A `marketplace_purchase` delivery. Its record belongs to no tenant, so the job has no TenantStamp.
 */
#[AsMessage('async')]
final readonly class MarketplacePurchaseJob
{
    public function __construct(
        public int $accountId,
        public MarketplacePurchaseAction $action,
        public MarketplacePurchasePayload $purchase,
        public \DateTimeImmutable $effectiveAt,
    ) {
    }
}
