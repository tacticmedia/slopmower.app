<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Before;

/**
 * Truncates instead of rolling back, because E2E and concurrency tests need committed rows (D36).
 */
trait ResetDatabase
{
    #[Before]
    protected function resetDatabase(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $tables = $connection->fetchFirstColumn(
            "SELECT quote_ident(tablename) FROM pg_tables WHERE schemaname = current_schema() AND tablename <> 'doctrine_migration_versions'",
        );

        if ([] !== $tables) {
            $connection->executeStatement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        }

        // WebTestCase::createClient() refuses a booted kernel.
        static::ensureKernelShutdown();
    }
}
