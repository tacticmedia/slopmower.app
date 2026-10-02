<?php

declare(strict_types=1);

namespace App\Anchoring;

use App\Domain\Identity\EventId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Domain\Location\Location;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Ulid;

/**
 * Two computations of one key store the same location. A unique key violation in the ORM closes the entity manager,
 * so the insert skips a conflicting row instead.
 *
 * The insert locks the repository, then the review of the `request.opened` event, FOR KEY SHARE: the weakest lock that
 * {@see \App\Repository\DeletionSql} waits for, in the order in which it locks them. An entry of a deleted review, or
 * of a repository whose access was removed, is not written.
 */
final class LocationCacheSql
{
    private const string LOCK_REPOSITORY = "SELECT id FROM repository WHERE tenant_id = :tenantId AND id = :repositoryId AND access = 'active' FOR KEY SHARE";
    private const string LOCK_REVIEW = <<<'SQL'
        SELECT review.id FROM review_event opened JOIN review ON review.id = opened.review_id
        WHERE opened.tenant_id = :tenantId AND review.tenant_id = :tenantId AND opened.id = :openedEventId AND review.repository_id = :repositoryId
        FOR KEY SHARE OF review
        SQL;
    private const string INSERT = <<<'SQL'
        INSERT INTO location_cache_entry (tenant_id, opened_event_id, target_sha, repository_id, status, path, start_line, end_line, confidence, reason)
        VALUES (:tenantId, :openedEventId, :targetSha, :repositoryId, :status, :path, :startLine, :endLine, :confidence, :reason)
        ON CONFLICT DO NOTHING
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(TenantId $tenantId, RepositoryId $repositoryId, EventId $openedEventId, Location $location): void
    {
        $key = ['tenantId' => self::uuid($tenantId), 'repositoryId' => self::uuid($repositoryId), 'openedEventId' => self::uuid($openedEventId)];

        $this->connection->transactional(function () use ($key, $location): void {
            $repository = array_intersect_key($key, ['tenantId' => true, 'repositoryId' => true]);

            if (false === $this->connection->fetchOne(self::LOCK_REPOSITORY, $repository) || false === $this->connection->fetchOne(self::LOCK_REVIEW, $key)) {
                return;
            }

            $this->connection->executeStatement(self::INSERT, $key + [
                'targetSha' => $location->targetSha->toString(),
                'status' => $location->status->value,
                'path' => $location->path?->toString(),
                'startLine' => $location->range?->startLine,
                'endLine' => $location->range?->endLine,
                'confidence' => $location->confidence,
                'reason' => $location->reason?->value,
            ], ['startLine' => Types::INTEGER, 'endLine' => Types::INTEGER, 'confidence' => Types::FLOAT]);
        });
    }

    /**
     * The ulid column type is a native uuid on PostgreSQL.
     */
    private static function uuid(\Stringable $id): string
    {
        return Ulid::fromString((string) $id)->toRfc4122();
    }
}
