<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Domain\Error\NotFound;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Entity\ChangeRequest;
use App\EventStore\EventsCommitted;
use App\Repository\ChangeRequestRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\EventStore\CommandFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class RollbackTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    private const string STORED_BODY = 'Use `Money` instead of `float` for totals.';

    #[TestDox('A decider that throws leaves event_seq, the events, and the projections unchanged and dispatches nothing')]
    public function testThrowingDeciderLeavesNoTrace(): void
    {
        [$tenantId, $reviewId, $requestId] = self::openedRequest();
        $before = self::storedRows($tenantId);
        $dispatched = self::recordDispatches();

        self::failToDecide($reviewId, $requestId);

        self::assertSame($before, self::storedRows($tenantId));
        self::assertCount(0, $dispatched);
    }

    #[TestDox('After a rollback, a read in the same process sees the stored projection')]
    public function testReadAfterRollbackSeesTheStoredProjection(): void
    {
        [, $reviewId, $requestId] = self::openedRequest();

        self::failToDecide($reviewId, $requestId);

        self::assertSame(self::STORED_BODY, self::request($requestId)->getBody());
    }

    #[TestDox('A failure after the append and a projection leaves event_seq, the events, and the projections unchanged')]
    public function testFailureAfterTheAppendLeavesNoTrace(): void
    {
        [$tenantId, $reviewId, $requestId] = self::openedRequest();
        $before = self::storedRows($tenantId);
        $notLinked = CommitSha::fromString(RequestScenario::LINKED_COMMIT);

        try {
            self::executor()->execute($reviewId, RequestScenario::user(), static fn (): array => [
                new DecidedEvent(new RequestCommentedEvent(CommentId::generate(), 'Projected, then rolled back.', false, null), $requestId),
                new DecidedEvent(new CommitUnlinkedEvent($notLinked, $requestId)),
            ]);
            self::fail('The projection of the unlink did not fail.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('not linked', $error->getMessage());
        }

        self::assertSame($before, self::storedRows($tenantId));
    }

    /**
     * @return array{TenantId, ReviewId, RequestId}
     */
    private static function openedRequest(): array
    {
        $tenantId = self::enterNewTenant();
        $reviewId = self::startReview(self::newRepository($tenantId));

        return [$tenantId, $reviewId, self::openRequest($reviewId)];
    }

    /**
     * The decider changes the managed projection, then refuses.
     */
    private static function failToDecide(ReviewId $reviewId, RequestId $requestId): void
    {
        $refusal = new NotFound();

        try {
            self::executor()->execute($reviewId, RequestScenario::user(), static function () use ($requestId, $refusal): array {
                $request = self::request($requestId);
                $request->opened($request->getAnchor(), 'Changed in memory.', $request->getAuthor(), $request->getCreatedAt());

                throw $refusal;
            });
            self::fail('The executor swallowed the refusal.');
        } catch (NotFound $error) {
            self::assertSame($refusal, $error);
        }
    }

    private static function request(RequestId $requestId): ChangeRequest
    {
        return self::getContainer()->get(ChangeRequestRepository::class)->find(Ulid::fromString($requestId->toString())) ?? self::fail('The request is missing.');
    }

    /**
     * @return \ArrayObject<int, EventsCommitted>
     */
    private static function recordDispatches(): \ArrayObject
    {
        /** @var \ArrayObject<int, EventsCommitted> $dispatched */
        $dispatched = new \ArrayObject();
        self::getContainer()->get('event_dispatcher')->addListener(EventsCommitted::class, static function (EventsCommitted $event) use ($dispatched): void {
            $dispatched[] = $event;
        });

        return $dispatched;
    }
}
