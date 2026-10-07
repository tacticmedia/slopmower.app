<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Container\ContainerInterface;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

final class LockStores
{
    /**
     * Advisory locks in a database session of their own, as the php and worker processes have.
     */
    public static function inAnotherSession(ContainerInterface $container): DoctrineDbalPostgreSqlStore
    {
        $lockConnection = $container->get('doctrine.dbal.lock_connection');
        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse((string) $_SERVER['DATABASE_URL']);

        return new DoctrineDbalPostgreSqlStore(DriverManager::getConnection([...$params, 'dbname' => (string) $lockConnection->getDatabase()]));
    }
}
