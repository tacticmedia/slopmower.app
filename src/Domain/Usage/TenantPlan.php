<?php

declare(strict_types=1);

namespace App\Domain\Usage;

/**
 * The plan of a tenant, with the trial and the pending change of the Marketplace account that gives it.
 */
final readonly class TenantPlan
{
    /**
     * @param Plan|null $pendingPlan The plan after the pending change, null without one
     */
    public function __construct(
        public Plan $plan,
        public ?\DateTimeImmutable $trialEndsAt = null,
        public ?Plan $pendingPlan = null,
        public ?\DateTimeImmutable $pendingEffectiveAt = null,
    ) {
    }
}
