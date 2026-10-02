<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootsTest extends KernelTestCase
{
    private const array REMOVED_PACKAGE_SERVICE_IDS = ['mailer', 'notifier', 'asset_mapper'];

    #[TestDox('The test kernel boots without mailer, notifier, or asset mapper services')]
    public function testKernelBootsWithoutRemovedPackageServices(): void
    {
        $container = static::getContainer();
        // has() misses unused private services, which the compiler removed. getRemovedIds() lists them.
        $removedIds = $container->getRemovedIds();

        foreach (self::REMOVED_PACKAGE_SERVICE_IDS as $id) {
            self::assertFalse($container->has($id) || isset($removedIds[$id]), "Service \"{$id}\" exists.");
        }
    }
}
