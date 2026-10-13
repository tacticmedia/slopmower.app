<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messenger;

use App\Message\PruneRateLimiterCacheJob;
use App\Message\PurgeFailedMessagesJob;
use App\Schedule;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\DependencyInjection\CachePoolPass;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class RetentionJobsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetDatabase;

    #[TestDox('The prune job deletes an expired row of rate_limiter.cache and keeps a live one')]
    public function testPruneDeletesExpiredRateLimiterRows(): void
    {
        $pool = static::getContainer()->get('rate_limiter.cache');
        $pool->save($pool->getItem('expired')->set(1)->expiresAfter(60));
        $pool->save($pool->getItem('live')->set(1)->expiresAfter(3600));
        // The pool stamps a row with time(), which the mock clock does not move.
        self::connection()->executeStatement('UPDATE cache_items SET item_time = item_time - 120');

        self::receive(new PruneRateLimiterCacheJob());

        self::assertSame(1, (int) self::connection()->fetchOne('SELECT count(*) FROM cache_items'));
        self::assertTrue($pool->getItem('live')->isHit());
    }

    #[TestDox('The rows of rate_limiter.cache have a namespace from the fixed prefix seed, not from the project directory')]
    public function testRateLimiterNamespaceIgnoresProjectDirectory(): void
    {
        $pool = static::getContainer()->get('rate_limiter.cache');
        $pool->save($pool->getItem('key')->set(1));
        $itemId = (string) self::connection()->fetchOne('SELECT item_id FROM cache_items');
        $getNamespace = new \ReflectionMethod(CachePoolPass::class, 'getNamespace');

        self::assertSame($getNamespace->invoke(new CachePoolPass(), 'slopmower/test.'.DoctrineDbalAdapter::class, 'rate_limiter.cache').':key', $itemId);
    }

    #[TestDox('The failed-message job deletes failed messages older than 30 days and keeps newer ones and every async message')]
    public function testPurgeDeletesOldFailedMessages(): void
    {
        self::mockTime('2026-10-13 03:00:00 UTC');
        self::sendFailedMessage('2026-09-13 02:59:59');
        $recentId = self::sendFailedMessage('2026-09-13 03:00:01');
        $asyncId = self::insertAsyncMessage('2026-01-01 00:00:00');

        self::receive(new PurgeFailedMessagesJob());

        self::assertSame([$recentId, $asyncId], array_map(intval(...), self::connection()->fetchFirstColumn('SELECT id FROM messenger_messages ORDER BY id')));
    }

    #[TestDox('The schedule runs both jobs once a day at 03:00 UTC')]
    public function testScheduleRunsBothJobsDaily(): void
    {
        $schedule = static::getContainer()->get(Schedule::class)->getSchedule();
        $jobs = [];

        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            $trigger = $recurringMessage->getTrigger();
            $firstRun = $trigger->getNextRunDate(new \DateTimeImmutable()) ?? self::fail('No next run.');

            self::assertSame('03:00:00 UTC', $firstRun->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s T'));
            self::assertEquals($firstRun->modify('+1 day'), $trigger->getNextRunDate($firstRun));

            foreach ($recurringMessage->getMessages(new MessageContext('default', $recurringMessage->getId(), $trigger, $firstRun)) as $job) {
                $jobs[] = $job::class;
            }
        }

        self::assertEqualsCanonicalizing([PruneRateLimiterCacheJob::class, PurgeFailedMessagesJob::class], $jobs);
    }

    /**
     * As the worker handles a message of the transport, so the tenant middleware runs.
     */
    private static function receive(object $job): void
    {
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($job, [new ReceivedStamp('scheduler_default')]));
    }

    private static function sendFailedMessage(string $createdAt): int
    {
        $id = (int) (static::getContainer()->get('messenger.transport.failed')->send(new Envelope(new \stdClass()))->last(TransportMessageIdStamp::class)?->getId() ?? self::fail('No message ID.'));

        self::connection()->executeStatement(
            'UPDATE messenger_messages SET created_at = :createdAt WHERE id = :id',
            ['createdAt' => new \DateTimeImmutable($createdAt), 'id' => $id],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return $id;
    }

    /**
     * The test environment routes async to in-memory://, so the row of its Doctrine queue `default` is written here.
     */
    private static function insertAsyncMessage(string $createdAt): int
    {
        return (int) self::connection()->fetchOne(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '[]', 'default', :createdAt, :createdAt) RETURNING id",
            ['createdAt' => new \DateTimeImmutable($createdAt)],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );
    }

    private static function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
