<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Application\EventLogQuery;
use App\Domain\Event\RecordedEvent;
use App\Domain\Request\Command\Reply;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\EventStore\CommandFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventLogQueryTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    #[TestDox("Returns only one review's events after the cursor")]
    public function testReturnsOnlyOneReviewsEventsAfterTheCursor(): void
    {
        $repositoryId = self::newRepository(self::enterNewTenant());
        $review = self::startReview($repositoryId);
        $otherReview = self::startReview($repositoryId);
        $request = self::openRequest($review);
        $otherRequest = self::openRequest($otherReview);
        self::requestCommands()->reply(new Reply($request, 'Also the shipping totals.'), RequestScenario::user());
        self::requestCommands()->reply(new Reply($otherRequest, 'Also the tax totals.'), RequestScenario::user());

        $events = self::getContainer()->get(EventLogQuery::class)->after($review, 1);

        self::assertSame([3, 5], array_map(static fn (RecordedEvent $event): int => $event->seq, $events));
        self::assertEquals([$review, $review], array_map(static fn (RecordedEvent $event) => $event->reviewId, $events));
    }
}
