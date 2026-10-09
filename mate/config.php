<?php

declare(strict_types=1);

// User's service configuration file
// This file is loaded into the Symfony DI container

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // The command your coding agent must use to run Mate. It is materialized into the
        // generated agent instructions, so a container prefix ends up where the agent reads it.
        ->set('mate.invocation', 'docker compose exec -T php vendor/bin/mate')

        // The PHP version this project runs on. Mate refuses to start under a different
        // major.minor, because it would then report on a runtime that is not your application's
        // and extensions may behave differently. Set to null to disable the check.
        ->set('mate.php_version', '8.5')

        // Loads <project>/.env and .env.local. The php container has no DATABASE_* variables.
        ->set('mate.env_file', '.env')
    ;

    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()

        // Do not register the tool classes here: Mate registers them as public services first, and a redefinition makes them private.

        ->set(Connection::class)
            ->factory(DriverManager::getConnection(...))
            ->args([[
                'driver' => 'pdo_pgsql',
                'host' => env('DATABASE_HOST'),
                'port' => env('DATABASE_PORT')->int(),
                'dbname' => env('DATABASE_NAME'),
                'user' => env('DATABASE_USER'),
                'password' => env('DATABASE_PASSWORD'),
            ]])
    ;
};
