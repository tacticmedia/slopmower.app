<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

final class LockStores
{
    /**
     * Advisory locks in a database session of their own, as the php and worker processes have.
     */
    public static function inAnotherSession(ContainerInterface $container): DoctrineDbalPostgreSqlStore
    {
        $applicationConnection = $container->get('doctrine.dbal.default_connection');

        return new DoctrineDbalPostgreSqlStore(DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => (string) $_SERVER['DATABASE_HOST'],
            'port' => (int) $_SERVER['DATABASE_PORT'],
            'dbname' => (string) $applicationConnection->getDatabase(),
            'user' => (string) $_SERVER['DATABASE_USER'],
            'password' => (string) $_SERVER['DATABASE_PASSWORD'],
        ]));
    }
}
