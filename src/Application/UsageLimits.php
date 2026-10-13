<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\LimitUsage;
use App\Domain\Error\LimitExceeded;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Usage\UsageLimit;
use App\Repository\UsageSql;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The usage limits of docs/specs/README.md section "Usage limits", with the values of the plan of the tenant. Every
 * tenant has the free plan.
 */
final class UsageLimits
{
    private const string PLAN = 'free';

    private const int BYTES_PER_MIB = 1_048_576;

    /**
     * @param array<string, array{repositories: int, open_reviews: int, requests_per_review: int, repository_size_mib: int, tenant_storage_mib: int}> $plans
     */
    public function __construct(
        #[Autowire(param: 'app.plans')]
        private readonly array $plans,
        private readonly UsageSql $usage,
    ) {
    }

    /**
     * @return list<LimitUsage>
     */
    public function usage(TenantId $tenantId): array
    {
        $use = $this->usage->tenantUse($tenantId);

        return array_map(
            fn (UsageLimit $limit): LimitUsage => new LimitUsage($limit, $this->max($limit), $use[$limit->value] ?? throw new \LogicException("No use of {$limit->value}.")),
            UsageLimit::cases(),
        );
    }

    /**
     * @throws LimitExceeded
     */
    public function checkNewRepository(TenantId $tenantId): void
    {
        $this->checkAdded(UsageLimit::Repositories, $this->usage->repositories($tenantId), 1);
    }

    /**
     * @throws LimitExceeded
     */
    public function checkNewReview(TenantId $tenantId): void
    {
        $this->checkAdded(UsageLimit::OpenReviews, $this->usage->openReviews($tenantId), 1);
    }

    /**
     * @throws LimitExceeded
     */
    public function checkNewRequests(TenantId $tenantId, ReviewId $reviewId, int $count): void
    {
        if ($count > 0) {
            $this->checkAdded(UsageLimit::RequestsPerReview, $this->usage->requestsOfReview($tenantId, $reviewId), $count);
        }
    }

    /**
     * @throws LimitExceeded The clone alone, or with the other clones of the tenant, is above a size limit
     */
    public function checkCloneSize(TenantId $tenantId, RepositoryId $repositoryId, int $sizeBytes): void
    {
        $this->checkTotal(UsageLimit::RepositorySize, $sizeBytes);
        $this->checkTotal(UsageLimit::TenantStorage, $this->usage->otherClonesBytes($tenantId, $repositoryId) + $sizeBytes);
    }

    /**
     * @throws LimitExceeded
     */
    private function checkAdded(UsageLimit $limit, int $current, int $added): void
    {
        $max = $this->max($limit);

        if (null !== $max && $current + $added > $max) {
            throw new LimitExceeded($limit, $max, $current);
        }
    }

    /**
     * @throws LimitExceeded
     */
    private function checkTotal(UsageLimit $limit, int $total): void
    {
        $max = $this->max($limit);

        if (null !== $max && $total > $max) {
            throw new LimitExceeded($limit, $max, $total);
        }
    }

    /**
     * @return int|null Null when the limit is off. Bytes for the size limits.
     */
    private function max(UsageLimit $limit): ?int
    {
        $plan = $this->plans[self::PLAN] ?? throw new \LogicException('The parameter app.plans has no free plan.');
        $max = match ($limit) {
            UsageLimit::Repositories => $plan['repositories'],
            UsageLimit::OpenReviews => $plan['open_reviews'],
            UsageLimit::RequestsPerReview => $plan['requests_per_review'],
            UsageLimit::RepositorySize => $plan['repository_size_mib'] * self::BYTES_PER_MIB,
            UsageLimit::TenantStorage => $plan['tenant_storage_mib'] * self::BYTES_PER_MIB,
        };

        return $max > 0 ? $max : null;
    }
}
