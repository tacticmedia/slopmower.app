<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MarketplaceAccountSql;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The GitHub Marketplace plan of one GitHub account, user or organization. Not tenant owned: one account can give its
 * plan to several tenants. Only {@see MarketplaceAccountSql} reads and writes it, so the entity has no repository.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_account')]
class MarketplaceAccount
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private int $accountId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $planId;

    /** `monthly` or `yearly` */
    #[ORM\Column]
    private string $billingCycle;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt;

    /** Null with a pending change: the change cancels the plan. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $pendingPlanId;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pendingEffectiveAt;

    public function __construct(int $accountId, int $planId, string $billingCycle, ?\DateTimeImmutable $trialEndsAt, ?int $pendingPlanId, ?\DateTimeImmutable $pendingEffectiveAt)
    {
        $this->accountId = $accountId;
        $this->planId = $planId;
        $this->billingCycle = $billingCycle;
        $this->trialEndsAt = $trialEndsAt;
        $this->pendingPlanId = $pendingPlanId;
        $this->pendingEffectiveAt = $pendingEffectiveAt;
    }
}
