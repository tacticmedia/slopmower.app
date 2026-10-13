<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * A `marketplace_purchase` object, as the `marketplace_purchase` webhook and `GET /marketplace_listing/accounts/{id}`
 * send it.
 */
final readonly class MarketplacePurchasePayload
{
    public function __construct(
        #[SerializedPath('[plan][id]')]
        public int $planId,
        #[SerializedName('billing_cycle')]
        public string $billingCycle,
        #[SerializedName('on_free_trial')]
        public bool $onFreeTrial = false,
        #[SerializedName('free_trial_ends_on')]
        public ?\DateTimeImmutable $freeTrialEndsOn = null,
    ) {
    }

    /**
     * GitHub can keep `free_trial_ends_on` after the trial.
     */
    public function trialEndsAt(): ?\DateTimeImmutable
    {
        return $this->onFreeTrial ? $this->freeTrialEndsOn : null;
    }
}
