<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResetDatabaseTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('Empties every table except doctrine_migration_versions, restarts identities, and shuts the kernel down')]
    public function testEmptiesEveryTableExceptMigrationVersions(): void
    {
        TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        self::queueMessage(self::getContainer()->get(Connection::class));

        $this->resetDatabase();

        self::assertFalse(self::$booted);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM tenant'));
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM app_user'));
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM messenger_messages'));
        self::assertGreaterThan(0, $connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        self::assertSame(1, self::queueMessage($connection));
    }

    private static function queueMessage(Connection $connection): int
    {
        $id = $connection->fetchOne(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
            VALUES ('{}', '{}', 'default', now(), now()) RETURNING id",
        );
        self::assertIsInt($id);

        return $id;
    }
}
