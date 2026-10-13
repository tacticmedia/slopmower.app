<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\LimitUsage;
use App\Domain\Error\LimitExceeded;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Usage\Plan;
use App\Domain\Usage\UsageLimit;
use App\Repository\UsageSql;

/**
 * The usage limits of docs/specs/README.md section "Usage limits", with the values of the plan of the tenant.
 */
final class UsageLimits
{
    public function __construct(
        private readonly Plans $plans,
        private readonly UsageSql $usage,
    ) {
    }

    /**
     * @return list<LimitUsage>
     */
    public function usage(TenantId $tenantId): array
    {
        $plan = $this->plans->ofTenant($tenantId)->plan;
        $use = $this->usage->tenantUse($tenantId);

        return array_map(
            static fn (UsageLimit $limit): LimitUsage => new LimitUsage($limit, $plan->max($limit), $use[$limit->value] ?? throw new \LogicException("No use of {$limit->value}.")),
            UsageLimit::cases(),
        );
    }

    /**
     * @throws LimitExceeded
     */
    public function checkNewRepository(TenantId $tenantId): void
    {
        self::checkAdded($this->plans->ofTenant($tenantId)->plan, UsageLimit::Repositories, $this->usage->repositories($tenantId), 1);
    }

    /**
     * @throws LimitExceeded
     */
    public function checkNewReview(TenantId $tenantId): void
    {
        self::checkAdded($this->plans->ofTenant($tenantId)->plan, UsageLimit::OpenReviews, $this->usage->openReviews($tenantId), 1);
    }

    /**
     * @throws LimitExceeded
     */
    public function checkNewRequests(TenantId $tenantId, ReviewId $reviewId, int $count): void
    {
        if ($count > 0) {
            self::checkAdded($this->plans->ofTenant($tenantId)->plan, UsageLimit::RequestsPerReview, $this->usage->requestsOfReview($tenantId, $reviewId), $count);
        }
    }

    /**
     * @throws LimitExceeded The clone alone, or with the other clones of the tenant, is above a size limit
     */
    public function checkCloneSize(TenantId $tenantId, RepositoryId $repositoryId, int $sizeBytes): void
    {
        $plan = $this->plans->ofTenant($tenantId)->plan;
        self::checkTotal($plan, UsageLimit::RepositorySize, $sizeBytes);
        self::checkTotal($plan, UsageLimit::TenantStorage, $this->usage->otherClonesBytes($tenantId, $repositoryId) + $sizeBytes);
    }

    /**
     * @throws LimitExceeded
     */
    private static function checkAdded(Plan $plan, UsageLimit $limit, int $current, int $added): void
    {
        $max = $plan->max($limit);

        if (null !== $max && $current + $added > $max) {
            throw new LimitExceeded($limit, $max, $current);
        }
    }

    /**
     * @throws LimitExceeded
     */
    private static function checkTotal(Plan $plan, UsageLimit $limit, int $total): void
    {
        $max = $plan->max($limit);

        if (null !== $max && $total > $max) {
            throw new LimitExceeded($limit, $max, $total);
        }
    }
}
