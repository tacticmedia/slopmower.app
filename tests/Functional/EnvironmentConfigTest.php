<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EnvironmentConfigTest extends KernelTestCase
{
    #[TestDox('The test kernel uses the in-memory transport and the database app_test')]
    public function testTestKernelIsIsolatedFromDevResources(): void
    {
        $container = static::getContainer();

        self::assertInstanceOf(InMemoryTransport::class, $container->get('messenger.transport.async'));
        self::assertSame('app_test', $container->get(Connection::class)->fetchOne('SELECT current_database()'));
    }
}
