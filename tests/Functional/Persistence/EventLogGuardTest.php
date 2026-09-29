<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Actor\Actor;
use App\Domain\Event\RequestClaimed;
use App\Entity\ReviewEvent;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\ReviewData;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventLogGuardTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('An ORM flush that deletes a review event fails and the row stays')]
    public function testAnOrmDeleteOfAReviewEventFails(): void
    {
        $user = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        $review = ReviewData::review(ReviewData::gitHubRepository($user->getTenantId()), Actor::user($user->getId()));
        $event = ReviewEvent::record(ReviewData::recordedEvent($user->getTenantId(), $review->getId(), 1, new RequestClaimed(), Actor::agent($user->getId(), 'claude-code laptop')));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($event);
        $entityManager->flush();

        $entityManager->remove($event);
        try {
            $entityManager->flush();
            self::fail('The flush deleted a review event.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM review_event'));
    }
}
