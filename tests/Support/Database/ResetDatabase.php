<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
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
            "SELECT quote_ident(tablename) FROM pg_tables WHERE schemaname = current_schema() AND tablename <> 'doctrine_migration_versions' ORDER BY tablename",
        );

        if ([] !== $tables) {
            self::truncateWithLockTimeout($connection, 'TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        }

        // WebTestCase::createClient() refuses a booted kernel.
        static::ensureKernelShutdown();
    }

    /**
     * TRUNCATE locks one table after the other. An e2e site request of the previous test can hold a table that TRUNCATE
     * locks late, then wait for one that TRUNCATE holds. The lock timeout, below the 1 s deadlock_timeout, releases
     * every lock before PostgreSQL aborts either side, and the next attempt waits for that request again.
     */
    private static function truncateWithLockTimeout(Connection $connection, string $truncate): void
    {
        $lockTimeout = '100ms';
        $maximumAttempts = 300;
        $lockNotAvailable = '55P03';

        for ($attempt = 1;; ++$attempt) {
            try {
                $connection->transactional(static function (Connection $connection) use ($truncate, $lockTimeout): void {
                    $connection->executeStatement("SET LOCAL lock_timeout = '{$lockTimeout}'");
                    $connection->executeStatement($truncate);
                });

                return;
            } catch (DriverException $exception) {
                if ($lockNotAvailable !== $exception->getSQLState() || $maximumAttempts === $attempt) {
                    throw $exception;
                }
            }
        }
    }
}
