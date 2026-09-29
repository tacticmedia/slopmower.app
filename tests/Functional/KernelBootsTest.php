<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function removedServiceIds(): iterable
    {
        yield 'mailer' => ['mailer.mailer'];
        yield 'notifier' => ['notifier'];
        yield 'asset mapper' => ['asset_mapper'];
    }

    #[DataProvider('removedServiceIds')]
    #[TestDox('The test kernel boots without mailer, notifier, or asset mapper services')]
    public function testKernelBootsWithoutRemovedService(string $serviceId): void
    {
        $container = self::getContainer();

        self::assertFalse($container->has($serviceId));
        // The compiler removes unused private services, and has() then no longer sees them.
        self::assertArrayNotHasKey($serviceId, $container->getRemovedIds());
    }
}
