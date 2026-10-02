<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Tests in tests/E2E seed the database of the e2e site, app_e2e. The test kernel reads TEST_DATABASE_SUFFIX when it
 * boots, and uses app_test without it, so one PHPUnit run can mix both kinds of tests.
 */
final class E2eDatabaseExtension implements Extension, PreparationStartedSubscriber
{
    private const string VARIABLE = 'TEST_DATABASE_SUFFIX';
    private const string E2E_NAMESPACE = 'App\\Tests\\E2E\\';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod && str_starts_with($test->className(), self::E2E_NAMESPACE)) {
            $_ENV[self::VARIABLE] = $_SERVER[self::VARIABLE] = '_e2e';
        } else {
            unset($_ENV[self::VARIABLE], $_SERVER[self::VARIABLE]);
        }
    }
}
