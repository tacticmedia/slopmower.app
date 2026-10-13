<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Connection;

/**
 * V13, R6, the tenant purge, and {@see \App\Message\DeleteCloneJob}. Each deletion first locks its parent rows FOR
 * UPDATE, so a draft or viewed-file insert, and a writer of {@see \App\Index\IndexSql} or
 * {@see \App\Anchoring\LocationCacheSql}, waits for it. The deletes are in foreign key order.
 */
final class DeletionSql
{
    private const array REVIEW_LOCKS = [
        'SELECT id FROM review WHERE tenant_id = :tenantId AND id = :reviewId FOR UPDATE',
    ];

    private const array REVIEW = [
        'DELETE FROM viewed_file WHERE tenant_id = :tenantId AND review_id = :reviewId',
        'DELETE FROM draft WHERE tenant_id = :tenantId AND review_id = :reviewId',
        'DELETE FROM location_cache_entry WHERE tenant_id = :tenantId AND opened_event_id IN (SELECT id FROM review_event WHERE tenant_id = :tenantId AND review_id = :reviewId)',
        'DELETE FROM comment WHERE tenant_id = :tenantId AND request_id IN (SELECT id FROM change_request WHERE tenant_id = :tenantId AND review_id = :reviewId)',
        'DELETE FROM commit_link WHERE tenant_id = :tenantId AND request_id IN (SELECT id FROM change_request WHERE tenant_id = :tenantId AND review_id = :reviewId)',
        'DELETE FROM change_request WHERE tenant_id = :tenantId AND review_id = :reviewId',
        'DELETE FROM review_event WHERE tenant_id = :tenantId AND review_id = :reviewId',
        'DELETE FROM review WHERE tenant_id = :tenantId AND id = :reviewId',
    ];

    private const array REPOSITORY_LOCKS = [
        'SELECT id FROM repository WHERE tenant_id = :tenantId AND id = :repositoryId FOR UPDATE',
        'SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId FOR UPDATE',
    ];

    private const array REPOSITORY = [
        'DELETE FROM viewed_file WHERE tenant_id = :tenantId AND review_id IN (SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId)',
        'DELETE FROM draft WHERE tenant_id = :tenantId AND review_id IN (SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId)',
        'DELETE FROM comment WHERE tenant_id = :tenantId AND request_id IN (SELECT id FROM change_request WHERE tenant_id = :tenantId AND review_id IN (SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId))',
        'DELETE FROM commit_link WHERE tenant_id = :tenantId AND request_id IN (SELECT id FROM change_request WHERE tenant_id = :tenantId AND review_id IN (SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId))',
        'DELETE FROM change_request WHERE tenant_id = :tenantId AND review_id IN (SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId)',
        'DELETE FROM review_event WHERE tenant_id = :tenantId AND review_id IN (SELECT id FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId)',
        'DELETE FROM review WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
        ...self::REPOSITORY_INDEX,
        'DELETE FROM pull_request WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
        'DELETE FROM api_token WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
        'DELETE FROM repository WHERE tenant_id = :tenantId AND id = :repositoryId',
    ];

    private const array REPOSITORY_INDEX_LOCKS = [
        'SELECT id FROM repository WHERE tenant_id = :tenantId AND id = :repositoryId FOR UPDATE',
    ];

    private const array REPOSITORY_INDEX = [
        'DELETE FROM location_cache_entry WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
        'DELETE FROM symbol WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
        'DELETE FROM indexed_blob WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
        'DELETE FROM indexed_file WHERE tenant_id = :tenantId AND repository_id = :repositoryId',
    ];

    private const array TENANT_LOCKS = [
        'SELECT id FROM repository WHERE tenant_id = :tenantId FOR UPDATE',
        'SELECT id FROM review WHERE tenant_id = :tenantId FOR UPDATE',
    ];

    /** Every table with a tenant column, then the tenant row. */
    private const array TENANT = [
        'DELETE FROM viewed_file WHERE tenant_id = :tenantId',
        'DELETE FROM draft WHERE tenant_id = :tenantId',
        'DELETE FROM comment WHERE tenant_id = :tenantId',
        'DELETE FROM commit_link WHERE tenant_id = :tenantId',
        'DELETE FROM change_request WHERE tenant_id = :tenantId',
        'DELETE FROM review_event WHERE tenant_id = :tenantId',
        'DELETE FROM review WHERE tenant_id = :tenantId',
        'DELETE FROM location_cache_entry WHERE tenant_id = :tenantId',
        'DELETE FROM symbol WHERE tenant_id = :tenantId',
        'DELETE FROM indexed_blob WHERE tenant_id = :tenantId',
        'DELETE FROM indexed_file WHERE tenant_id = :tenantId',
        'DELETE FROM pull_request WHERE tenant_id = :tenantId',
        'DELETE FROM api_token WHERE tenant_id = :tenantId',
        'DELETE FROM github_candidate WHERE tenant_id = :tenantId',
        'DELETE FROM github_installation WHERE tenant_id = :tenantId',
        'DELETE FROM repository WHERE tenant_id = :tenantId',
        'DELETE FROM app_user WHERE tenant_id = :tenantId',
        'DELETE FROM tenant WHERE id = :tenantId',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function deleteReview(TenantId $tenantId, ReviewId $reviewId): void
    {
        $this->run(self::REVIEW_LOCKS, self::REVIEW, ['tenantId' => $tenantId->toRfc4122(), 'reviewId' => $reviewId->toRfc4122()]);
    }

    public function deleteRepository(TenantId $tenantId, RepositoryId $repositoryId): void
    {
        $this->run(self::REPOSITORY_LOCKS, self::REPOSITORY, ['tenantId' => $tenantId->toRfc4122(), 'repositoryId' => $repositoryId->toRfc4122()]);
    }

    /**
     * The index and the location cache, which need the clone. The repository row and its reviews stay.
     */
    public function deleteRepositoryIndex(TenantId $tenantId, RepositoryId $repositoryId): void
    {
        $this->run(self::REPOSITORY_INDEX_LOCKS, self::REPOSITORY_INDEX, ['tenantId' => $tenantId->toRfc4122(), 'repositoryId' => $repositoryId->toRfc4122()]);
    }

    /**
     * Also without a tenant row, for the rows that a writer left after an earlier purge.
     */
    public function deleteTenant(TenantId $tenantId): void
    {
        $this->run(self::TENANT_LOCKS, self::TENANT, ['tenantId' => $tenantId->toRfc4122()]);
    }

    /**
     * In a transaction of its own, or in a savepoint of the caller's transaction.
     *
     * @param list<string>          $locks
     * @param list<string>          $deletes
     * @param array<string, string> $parameters
     */
    private function run(array $locks, array $deletes, array $parameters): void
    {
        $this->connection->transactional(function () use ($locks, $deletes, $parameters): void {
            foreach ($locks as $lock) {
                $this->connection->executeQuery($lock, $parameters)->free();
            }

            foreach ($deletes as $delete) {
                $this->connection->executeStatement($delete, $parameters);
            }
        });
    }
}
