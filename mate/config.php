<?php

declare(strict_types=1);

// User's service configuration file
// This file is loaded into the Symfony DI container

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\expr;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // The command your coding agent must use to run Mate. It is materialized into the
        // generated agent instructions, so a container prefix ends up where the agent reads it.
        ->set('mate.invocation', 'docker compose exec -T php vendor/bin/mate')

        // The PHP version this project runs on. Mate refuses to start under a different
        // major.minor, because it would then report on a runtime that is not your application's
        // and extensions may behave differently. Set to null to disable the check.
        ->set('mate.php_version', '8.5')

        // Loads <project>/.env and .env.local. The php container has no DATABASE_URL.
        ->set('mate.env_file', '.env')
    ;

    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()

        // Do not register the tool classes here: Mate registers them as public services first, and a redefinition makes them private.

        // Public: Mate does not dump its container, so the expression below gets this service at run time.
        ->set('mate.dsn_parser', DsnParser::class)
            ->public()
            ->args([['postgresql' => 'pdo_pgsql']])
        ->set(Connection::class)
            ->factory(DriverManager::getConnection(...))
            ->args([expr("service('mate.dsn_parser').parse(env('DATABASE_URL'))")])
    ;
};
