<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Identity\RepositoryId;
use App\Git\RepositoryLock;
use App\Tests\Support\Database\LockStores;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;

final class RepositoryLockTest extends KernelTestCase
{
    #[TestDox('Different repositories do not block each other')]
    public function testDifferentRepositoriesDoNotBlock(): void
    {
        $first = self::holder()->create(RepositoryId::generate());
        $second = self::holder()->create(RepositoryId::generate());

        self::assertTrue($first->acquire());
        self::assertTrue($second->acquire());
    }

    /**
     * Each holder has its own database session, as the php and worker processes have.
     */
    private static function holder(): RepositoryLock
    {
        return new RepositoryLock(new LockFactory(LockStores::inAnotherSession(self::getContainer())), new NativeClock());
    }
}
