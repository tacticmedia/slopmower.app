<?php

declare(strict_types=1);

namespace App\Tests\Support\Deletion;

use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

/**
 * Row counts of a tenant, for KernelTestCase tests.
 */
trait TenantRows
{
    /**
     * @return array<string, int> The rows of the tenant in each table that has a tenant_id column, by table name
     */
    private static function tenantRowCounts(TenantId $tenantId): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $tables = $connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'tenant_id' ORDER BY table_name",
        );
        $counts = [];

        foreach ($tables as $table) {
            $counts[(string) $table] = (int) $connection->fetchOne("SELECT count(*) FROM {$table} WHERE tenant_id = ?", [Ulid::fromString($tenantId->toString())->toRfc4122()]);
        }

        return $counts;
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, int> In the order of $tables
     */
    private static function tenantRowCountsOf(TenantId $tenantId, array $tables): array
    {
        $counts = self::tenantRowCounts($tenantId);

        return array_combine($tables, array_map(static fn (string $table): int => $counts[$table] ?? self::fail("The table {$table} has no tenant_id column."), $tables));
    }
}
