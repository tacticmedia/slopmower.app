<?php

declare(strict_types=1);

namespace App\Tests\Functional\Live;

use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\CommentId;
use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\EventStore\CommandFixtures;
use App\Tests\Support\Live\PublishedUpdates;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublishAfterCommitTest extends KernelTestCase
{
    use CommandFixtures;
    use PublishedUpdates;
    use ResetDatabase;

    #[TestDox('One committed transaction publishes one private update with IDs only')]
    public function testOneTransactionPublishesOneUpdate(): void
    {
        $tenantId = self::enterNewTenant();
        $reviewId = self::startReview(self::newRepository($tenantId));
        $requestId = self::openRequest($reviewId);
        $before = \count(self::publishedUpdates());

        $recorded = self::executor()->execute($reviewId, RequestScenario::user(), static fn (): array => [
            new DecidedEvent(new RequestCommentedEvent(CommentId::generate(), 'Keep the old method.', false, null), $requestId),
            new DecidedEvent(new RequestCommentedEvent(CommentId::generate(), 'Rename it instead.', false, null), $requestId),
        ]);

        $updates = \array_slice(self::publishedUpdates(), $before);
        self::assertCount(1, $updates);
        $lastSeq = $recorded[1]->seq;
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame(["http://localhost/tenants/{$tenantId}/reviews/{$reviewId}"], $updates[0]->getTopics());
        self::assertSame("urn:slopmower:{$tenantId}:{$lastSeq}", $updates[0]->getId());
        self::assertSame(['type' => 'events', 'reviewId' => $reviewId->toString(), 'seq' => $lastSeq, 'requestIds' => [$requestId->toString()]], self::updateData($updates[0]));
    }

    #[TestDox('The update validates against live-update.schema.json')]
    public function testUpdateValidatesAgainstTheSchema(): void
    {
        $reviewId = self::startReview(self::newRepository(self::enterNewTenant()));
        self::openRequest($reviewId);

        $updates = self::publishedUpdates();

        self::assertCount(2, $updates);
        self::assertSame([], self::updateData($updates[0])['requestIds'] ?? null, 'review.started concerns no request.');

        foreach ($updates as $update) {
            self::assertSame([], SchemaValidator::errors($update->getData(), 'live-update.schema.json'), $update->getData());
        }
    }

    #[TestDox('A rollback publishes nothing')]
    public function testRollbackPublishesNothing(): void
    {
        $reviewId = self::startReview(self::newRepository(self::enterNewTenant()));
        $requestId = self::openRequest($reviewId);
        $before = \count(self::publishedUpdates());

        try {
            // The projection of the unlink fails after the append.
            self::executor()->execute($reviewId, RequestScenario::user(), static fn (): array => [
                new DecidedEvent(new RequestCommentedEvent(CommentId::generate(), 'Appended, then rolled back.', false, null), $requestId),
                new DecidedEvent(new CommitUnlinkedEvent(CommitSha::fromString(RequestScenario::LINKED_COMMIT), $requestId)),
            ]);
            self::fail('The projection of the unlink did not fail.');
        } catch (\LogicException) {
        }

        self::assertCount($before, self::publishedUpdates());
    }
}
