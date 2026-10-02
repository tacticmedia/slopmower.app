<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Domain\Event\ReviewStartedEvent;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Entity\ReviewEvent;
use App\EventStore\EventStoreSql;
use App\Repository\ReviewEventRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\EventStore\CommandFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class GaplessSequenceTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    private const int EVENTS_PER_WRITER = 25;

    #[TestDox('Concurrent writers produce the numbers 1 to 50 exactly once, and the numbers of each writer increase')]
    public function testConcurrentWritersNumberWithoutGaps(): void
    {
        $tenantId = self::enterNewTenant();
        $repositoryId = self::newRepository($tenantId);
        $connection = self::getContainer()->get(Connection::class);

        // The writers queue on the tenant row lock until both are ready.
        $connection->beginTransaction();
        new EventStoreSql($connection)->lockTenant($tenantId);
        $processes = [self::writer($tenantId, $repositoryId, 'a'), self::writer($tenantId, $repositoryId, 'b')];

        foreach ($processes as $process) {
            self::awaitReady($process);
        }

        $connection->commit();

        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $seqsByWriter = [];

        foreach (self::getContainer()->get(ReviewEventRepository::class)->findBy([], ['seq' => 'ASC']) as $event) {
            [$writer, $index] = explode(' ', self::title($event));
            $seqsByWriter[$writer][(int) $index] = $event->toRecordedEvent()->seq;
        }

        $allSeqs = array_merge(...array_values($seqsByWriter));
        sort($allSeqs);
        self::assertSame(range(1, 2 * self::EVENTS_PER_WRITER), $allSeqs);

        foreach ($seqsByWriter as $writer => $seqs) {
            ksort($seqs);
            $increasing = array_values($seqs);
            sort($increasing);
            self::assertSame($increasing, array_values($seqs), "The numbers of writer {$writer} do not increase.");
        }
    }

    private static function writer(TenantId $tenantId, RepositoryId $repositoryId, string $name): Process
    {
        $process = new Process(
            [\PHP_BINARY, 'tests/Support/bin/append-events.php', $tenantId->toString(), $repositoryId->toString(), $name, (string) self::EVENTS_PER_WRITER],
            dirname(__DIR__, 3),
            ['APP_ENV' => 'test'],
        );
        $process->start();

        return $process;
    }

    /**
     * The iterator also yields output that the process read before, which waitUntil() does not pass to its callback.
     */
    private static function awaitReady(Process $process): void
    {
        foreach ($process->getIterator(Process::ITER_SKIP_ERR | Process::ITER_KEEP_OUTPUT) as $output) {
            if (str_contains($output, 'ready')) {
                return;
            }
        }
    }

    private static function title(ReviewEvent $event): string
    {
        $started = $event->toRecordedEvent()->event;

        return $started instanceof ReviewStartedEvent ? $started->title : self::fail('Only review.started was expected.');
    }
}
