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
 */
final class LocationCacheSql
{
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
        $this->connection->executeStatement(self::INSERT, [
            'tenantId' => self::uuid($tenantId),
            'openedEventId' => self::uuid($openedEventId),
            'targetSha' => $location->targetSha->toString(),
            'repositoryId' => self::uuid($repositoryId),
            'status' => $location->status->value,
            'path' => $location->path?->toString(),
            'startLine' => $location->range?->startLine,
            'endLine' => $location->range?->endLine,
            'confidence' => $location->confidence,
            'reason' => $location->reason?->value,
        ], ['startLine' => Types::INTEGER, 'endLine' => Types::INTEGER, 'confidence' => Types::FLOAT]);
    }

    /**
     * The ulid column type is a native uuid on PostgreSQL.
     */
    private static function uuid(\Stringable $id): string
    {
        return Ulid::fromString((string) $id)->toRfc4122();
    }
}
