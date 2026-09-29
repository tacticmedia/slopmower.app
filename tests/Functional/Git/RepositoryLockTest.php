<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Identity\RepositoryId;
use App\Git\RepositoryLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The second holder runs in a second kernel with its own database session, as the php and worker containers do.
 */
final class RepositoryLockTest extends KernelTestCase
{
    private ?KernelInterface $secondKernel = null;

    #[TestDox('The same repository blocks a second holder')]
    public function testSameRepositoryBlocksASecondHolder(): void
    {
        $repositoryId = RepositoryId::generate();
        $firstLock = self::getContainer()->get(RepositoryLock::class)->create($repositoryId);
        self::assertTrue($firstLock->acquire());
        self::assertSame(1, self::grantedAdvisoryLockCount($repositoryId));

        $secondLock = $this->secondKernelRepositoryLock()->create($repositoryId);
        self::assertFalse($secondLock->acquire());

        $firstLock->release();
        self::assertTrue($secondLock->acquire());
        self::assertFalse(self::getContainer()->get(LockFactory::class)->createLock('fetch:'.$repositoryId->toString())->acquire());
        $secondLock->release();
    }

    #[TestDox('A lock object dropped without release frees the lock for a second holder')]
    public function testALockObjectDroppedWithoutReleaseFreesTheLock(): void
    {
        $repositoryId = RepositoryId::generate();
        $firstLock = self::getContainer()->get(RepositoryLock::class)->create($repositoryId);
        self::assertTrue($firstLock->acquire());
        self::assertSame(1, self::grantedAdvisoryLockCount($repositoryId));

        unset($firstLock);

        self::assertSame(0, self::grantedAdvisoryLockCount($repositoryId));
        $secondLock = $this->secondKernelRepositoryLock()->create($repositoryId);
        self::assertTrue($secondLock->acquire());
        $secondLock->release();
    }

    #[TestDox('Different repositories do not block each other')]
    public function testDifferentRepositoriesDoNotBlockEachOther(): void
    {
        $firstLock = self::getContainer()->get(RepositoryLock::class)->create(RepositoryId::generate());
        self::assertTrue($firstLock->acquire());

        $secondLock = $this->secondKernelRepositoryLock()->create(RepositoryId::generate());
        self::assertTrue($secondLock->acquire());

        $secondLock->release();
        $firstLock->release();
    }

    #[After]
    protected function shutDownSecondKernel(): void
    {
        $this->secondKernel?->shutdown();
        $this->secondKernel = null;
    }

    /**
     * Counts the PostgreSQL advisory locks of the key that other sessions hold. The Symfony store locks the bigint
     * crc32(<key>), which pg_locks shows as classid 0, objid <crc32>, and objsubid 1.
     */
    private static function grantedAdvisoryLockCount(RepositoryId $repositoryId): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted AND pid <> pg_backend_pid() AND classid = 0 AND objid = :objid AND objsubid = 1",
            ['objid' => (string) crc32('fetch:'.$repositoryId->toString())],
        );
    }

    private function secondKernelRepositoryLock(): RepositoryLock
    {
        if (null === $this->secondKernel) {
            $this->secondKernel = self::createKernel();
            $this->secondKernel->boot();
        }

        return $this->secondKernel->getContainer()->get(RepositoryLock::class);
    }
}
