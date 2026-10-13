<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * The answer of `GET /marketplace_listing/accounts/{account_id}`.
 */
final readonly class MarketplaceAccountPayload
{
    public function __construct(
        public int $id,
        #[SerializedName('marketplace_purchase')]
        public MarketplacePurchasePayload $purchase,
        #[SerializedPath('[marketplace_pending_change?][effective_date]')]
        public ?\DateTimeImmutable $pendingEffectiveAt = null,
        #[SerializedPath('[marketplace_pending_change?][plan?][id]')]
        public ?int $pendingPlanId = null,
    ) {
    }
}
