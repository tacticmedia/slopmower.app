<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Identity\TenantId;
use App\Domain\Usage\Plan;
use App\Domain\Usage\TenantPlan;
use App\Repository\MarketplaceAccountSql;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The plans of the instance and the plan of a tenant, docs/specs/webhooks-and-live-updates.md section "Marketplace
 * plans" (D65).
 */
final class Plans
{
    private const array LIMIT_KEYS = ['repositories', 'open_reviews', 'requests_per_review', 'repository_size_mib', 'tenant_storage_mib'];

    private readonly Plan $free;

    /** @var array<int, Plan> By Marketplace plan ID */
    private readonly array $byMarketplacePlanId;

    /**
     * @param array{free: array{marketplace_plan_id: int, repositories: int, open_reviews: int, requests_per_review: int, repository_size_mib: int, tenant_storage_mib: int}} $plans
     * @param array<mixed>|null                                                                                                                                               $paidPlans `APP_PAID_PLANS`, checked here
     *
     * @throws \InvalidArgumentException A paid plan that is not a valid entry
     */
    public function __construct(
        #[Autowire(param: 'app.plans')]
        array $plans,
        #[Autowire(param: 'app.paid_plans')]
        ?array $paidPlans,
        private readonly MarketplaceAccountSql $accounts,
        private readonly LoggerInterface $logger,
    ) {
        $free = $plans['free'];
        $this->free = new Plan(Plan::FREE, Plan::FREE_NAME, 0, self::limits(static fn (string $limitKey): int => $free[$limitKey]));
        $byMarketplacePlanId = $free['marketplace_plan_id'] > 0 ? [$free['marketplace_plan_id'] => $this->free] : [];

        $keysByRank = [];

        foreach ($paidPlans ?? [] as $key => $values) {
            [$marketplacePlanId, $plan] = self::paidPlan($key, $values);

            if (isset($byMarketplacePlanId[$marketplacePlanId])) {
                throw new \InvalidArgumentException("APP_PAID_PLANS: the Marketplace plan ID {$marketplacePlanId} of \"{$key}\" belongs to another plan too.");
            }

            // Of two plans with one rank, the plan of a tenant would depend on the row order.
            if (isset($keysByRank[$plan->rank])) {
                throw new \InvalidArgumentException("APP_PAID_PLANS: the plans \"{$keysByRank[$plan->rank]}\" and \"{$key}\" have the same rank {$plan->rank}.");
            }

            $byMarketplacePlanId[$marketplacePlanId] = $plan;
            $keysByRank[$plan->rank] = $key;
        }

        $this->byMarketplacePlanId = $byMarketplacePlanId;
    }

    /**
     * The plan with the highest rank among the Marketplace accounts of the tenant, else the free plan.
     */
    public function ofTenant(TenantId $tenantId): TenantPlan
    {
        $tenantPlan = new TenantPlan($this->free);

        foreach ($this->accounts->ofTenant($tenantId) as $account) {
            $plan = $this->plan($account['planId']);

            if ($plan->rank > $tenantPlan->plan->rank) {
                $pendingPlan = null === $account['pendingEffectiveAt'] ? null : $this->plan($account['pendingPlanId']);
                $tenantPlan = new TenantPlan($plan, $account['trialEndsAt'], $pendingPlan, $account['pendingEffectiveAt']);
            }
        }

        return $tenantPlan;
    }

    /**
     * @param int|null $marketplacePlanId Null for a cancellation
     */
    private function plan(?int $marketplacePlanId): Plan
    {
        if (null === $marketplacePlanId) {
            return $this->free;
        }

        $plan = $this->byMarketplacePlanId[$marketplacePlanId] ?? null;

        if (null === $plan) {
            $this->logger->error('The Marketplace plan {marketplacePlanId} is not a plan of the instance, so it counts as the free plan. Add it to APP_PAID_PLANS.', ['marketplacePlanId' => $marketplacePlanId]);
        }

        return $plan ?? $this->free;
    }

    /**
     * @return array{int, Plan}
     *
     * @throws \InvalidArgumentException
     */
    private static function paidPlan(int|string $key, mixed $values): array
    {
        $fields = ['name', 'marketplace_plan_id', 'rank', ...self::LIMIT_KEYS];
        $invalid = static fn (string $problem): \InvalidArgumentException => new \InvalidArgumentException("APP_PAID_PLANS: the plan \"{$key}\" {$problem}. Each plan has the keys ".implode(', ', $fields).'.');

        if (!\is_string($key) || '' === $key || Plan::FREE === $key) {
            throw $invalid('needs a key other than "free"');
        }

        if (!\is_array($values) || [] !== array_diff(array_keys($values), $fields) || [] !== array_diff($fields, array_keys($values))) {
            throw $invalid('has other keys');
        }

        if (!\is_string($values['name']) || '' === $values['name']) {
            throw $invalid('needs a name');
        }

        if (!\is_int($values['marketplace_plan_id']) || $values['marketplace_plan_id'] < 1 || !\is_int($values['rank']) || $values['rank'] < 1) {
            throw $invalid('needs a marketplace_plan_id and a rank of at least 1');
        }

        $limits = self::limits(static fn (string $limitKey): int => \is_int($values[$limitKey]) && $values[$limitKey] >= 0 ? $values[$limitKey] : throw $invalid("needs an integer {$limitKey} of at least 0"));

        return [$values['marketplace_plan_id'], new Plan($key, $values['name'], $values['rank'], $limits)];
    }

    /**
     * @param \Closure(string): int $value The value of a key of {@see self::LIMIT_KEYS}
     *
     * @return array{repositories: int, open_reviews: int, requests_per_review: int, repository_size_mib: int, tenant_storage_mib: int}
     */
    private static function limits(\Closure $value): array
    {
        return [
            'repositories' => $value('repositories'),
            'open_reviews' => $value('open_reviews'),
            'requests_per_review' => $value('requests_per_review'),
            'repository_size_mib' => $value('repository_size_mib'),
            'tenant_storage_mib' => $value('tenant_storage_mib'),
        ];
    }
}
