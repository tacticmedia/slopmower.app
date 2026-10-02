<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Identity\RepositoryId;
use App\Git\RepositoryLock;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\StoreFactory;

final class RepositoryLockTest extends KernelTestCase
{
    #[TestDox('The same repository blocks a second holder')]
    public function testSameRepositoryBlocks(): void
    {
        $repositoryId = RepositoryId::generate();
        $first = self::holder()->create($repositoryId);
        $second = self::holder()->create($repositoryId);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
    }

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
        return new RepositoryLock(new LockFactory(StoreFactory::createStore((string) $_SERVER['LOCK_DSN'])));
    }
}
