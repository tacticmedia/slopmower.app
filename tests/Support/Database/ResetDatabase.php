<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Truncates every table except doctrine_migration_versions before each test, decision D36.
 * Shuts the kernel down afterwards, because WebTestCase::createClient() refuses a booted kernel.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait ResetDatabase
{
    #[Before]
    protected function resetDatabase(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $tables = $connection->fetchFirstColumn(
            "SELECT format('%I.%I', schemaname, tablename) FROM pg_tables
            WHERE schemaname = current_schema() AND tablename <> 'doctrine_migration_versions'
            ORDER BY tablename",
        );
        if ([] !== $tables) {
            $connection->executeStatement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        }

        self::ensureKernelShutdown();
    }
}
