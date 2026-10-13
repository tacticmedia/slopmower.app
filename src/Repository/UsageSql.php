<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Review\ReviewState;
use App\Domain\Usage\UsageLimit;
use Doctrine\DBAL\Connection;

/**
 * The use of the tenant for {@see \App\Application\UsageLimits}. Raw SQL, so the repository scope of a token never
 * hides a row of the tenant.
 */
final class UsageSql
{
    private const string TENANT_USE = <<<'SQL'
        SELECT
            (SELECT count(*) FROM repository WHERE tenant_id = :tenantId) AS repositories,
            (SELECT count(*) FROM review WHERE tenant_id = :tenantId AND state = :open) AS open_reviews,
            (SELECT coalesce(max(requests), 0) FROM (
                SELECT count(*) AS requests FROM change_request
                JOIN review ON review.id = change_request.review_id AND review.tenant_id = :tenantId AND review.state = :open
                WHERE change_request.tenant_id = :tenantId
                GROUP BY change_request.review_id
            ) AS open_review_requests) AS requests_per_review,
            (SELECT coalesce(max(clone_size_bytes), 0) FROM repository WHERE tenant_id = :tenantId) AS repository_size,
            (SELECT coalesce(sum(clone_size_bytes), 0) FROM repository WHERE tenant_id = :tenantId) AS tenant_storage
        SQL;
    private const string REPOSITORIES = 'SELECT count(*) FROM repository WHERE tenant_id = :tenantId';
    private const string OPEN_REVIEWS = 'SELECT count(*) FROM review WHERE tenant_id = :tenantId AND state = :open';
    private const string REQUESTS_OF_REVIEW = 'SELECT count(*) FROM change_request WHERE tenant_id = :tenantId AND review_id = :reviewId';
    private const string OTHER_CLONES = 'SELECT coalesce(sum(clone_size_bytes), 0) FROM repository WHERE tenant_id = :tenantId AND id <> :repositoryId';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, int> By {@see UsageLimit} value. For requests_per_review the largest open review, for
     *                            repository_size the largest clone.
     */
    public function tenantUse(TenantId $tenantId): array
    {
        $row = $this->connection->fetchAssociative(self::TENANT_USE, ['tenantId' => $tenantId->toRfc4122(), 'open' => ReviewState::Open->value]);
        \assert(false !== $row);

        return array_map(intval(...), $row);
    }

    public function repositories(TenantId $tenantId): int
    {
        return (int) $this->connection->fetchOne(self::REPOSITORIES, ['tenantId' => $tenantId->toRfc4122()]);
    }

    public function openReviews(TenantId $tenantId): int
    {
        return (int) $this->connection->fetchOne(self::OPEN_REVIEWS, ['tenantId' => $tenantId->toRfc4122(), 'open' => ReviewState::Open->value]);
    }

    public function requestsOfReview(TenantId $tenantId, ReviewId $reviewId): int
    {
        return (int) $this->connection->fetchOne(self::REQUESTS_OF_REVIEW, ['tenantId' => $tenantId->toRfc4122(), 'reviewId' => $reviewId->toRfc4122()]);
    }

    public function otherClonesBytes(TenantId $tenantId, RepositoryId $repositoryId): int
    {
        return (int) $this->connection->fetchOne(self::OTHER_CLONES, ['tenantId' => $tenantId->toRfc4122(), 'repositoryId' => $repositoryId->toRfc4122()]);
    }
}
