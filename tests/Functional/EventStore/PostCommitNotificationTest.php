<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\EventStore\EventsCommitted;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\EventStore\CommandFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class PostCommitNotificationTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    #[TestDox('The listener runs only when a second connection can already read the rows')]
    public function testListenerRunsAfterTheCommit(): void
    {
        $tenantId = self::enterNewTenant();
        $repositoryId = self::newRepository($tenantId);
        /** @var \ArrayObject<int, array{int, int, int, list<int>}> $seen */
        $seen = new \ArrayObject();
        self::getContainer()->get('event_dispatcher')->addListener(EventsCommitted::class, static function (EventsCommitted $event) use ($seen): void {
            $seen[] = [$event->lastSeq, ...self::readFromSecondConnection($event->tenantId)];
        });

        self::startReview($repositoryId);
        self::startReview($repositoryId);

        self::assertSame([[1, 1, 1, [1]], [2, 2, 2, [1, 2]]], $seen->getArrayCopy());
    }

    #[TestDox('The executor refuses to run inside an open transaction')]
    public function testExecutorRefusesAnOpenTransaction(): void
    {
        self::enterNewTenant();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        $this->expectExceptionObject(new \LogicException('The command executor runs only outside a database transaction.'));

        try {
            self::executor()->execute(ReviewId::generate(), RequestScenario::user(), static fn (): array => self::fail('The executor decided inside a transaction.'));
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return array{int, int, list<int>} event_seq, the event count, and the lastEventSeq of the reviews
     */
    private static function readFromSecondConnection(TenantId $tenantId): array
    {
        $second = DriverManager::getConnection(self::getContainer()->get(Connection::class)->getParams());
        $tenant = [Ulid::fromString($tenantId->toString())->toRfc4122()];

        try {
            return [
                (int) $second->fetchOne('SELECT event_seq FROM tenant WHERE id = ?', $tenant),
                (int) $second->fetchOne('SELECT count(*) FROM review_event WHERE tenant_id = ?', $tenant),
                array_map(intval(...), $second->fetchFirstColumn('SELECT last_event_seq FROM review WHERE tenant_id = ? ORDER BY last_event_seq', $tenant)),
            ];
        } finally {
            $second->close();
        }
    }
}
