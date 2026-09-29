<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Event\Codec\ActorCodec;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\EventType;
use App\Domain\Event\ReviewClosed;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Repository\SyncState;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\IndexState;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\ReviewEvent;
use App\Projection\RequestStateMapper;
use App\Repository\CommitLinkRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\ReviewData;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Unit\Domain\Event\EventFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityRoundTripTest extends KernelTestCase
{
    use ResetDatabase;

    private const string AGENT_NAME = 'claude-code laptop (v2.1)';

    private TenantId $tenantId;
    private UserId $userId;

    protected function setUp(): void
    {
        $user = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        $this->tenantId = $user->getTenantId();
        $this->userId = $user->getId();
        self::getContainer()->get(TenantContext::class)->set($this->tenantId);
    }

    #[TestDox('Each entity reloads with equal values')]
    public function testEachEntityReloadsWithEqualValues(): void
    {
        $entities = $this->everyEntityWithEveryColumnSet();
        $expected = array_map(EntitySnapshot::of(...), $entities);
        self::store(...$entities);

        self::assertSame($expected, array_map(static fn (object $entity): array => EntitySnapshot::of(self::reload($entity)), $entities));
        self::assertSame(
            '{}',
            self::getContainer()->get(Connection::class)->fetchOne("SELECT payload::text FROM review_event WHERE type = 'request.claimed'"),
            'An empty payload stays a JSON object.',
        );
    }

    #[TestDox('Anchors with a symbol and a truncated snapshot reload unchanged')]
    public function testAnchorsWithASymbolAndATruncatedSnapshotReloadUnchanged(): void
    {
        $anchor = self::symbolAnchorWithTruncatedSnapshot();
        self::assertNotNull($anchor->snapshot);
        self::assertTrue($anchor->snapshot->truncated);
        $request = ReviewData::request($this->review(), Actor::user($this->userId), anchor: $anchor);
        self::store($request->getReview()->getRepository(), $request->getReview(), $request);

        $reloaded = self::reload($request);

        self::assertTrue($anchor->equals($reloaded->getAnchor()));
        self::assertSame(AnchorCodec::encode($anchor), AnchorCodec::encode($reloaded->getAnchor()));
        self::assertSame(
            ['symbol', 'App\Billing\InvoiceTotals::total()', 'true', 200, 250],
            self::getContainer()->get(Connection::class)->fetchNumeric(
                "SELECT anchor->>'kind', anchor->'symbol'->>'fqn', anchor->'snapshot'->>'truncated',
                    jsonb_array_length(anchor->'snapshot'->'lines'), (anchor->'snapshot'->>'lineCount')::int
                FROM change_request",
            ),
        );
    }

    /**
     * @return iterable<string, array{\Closure(UserId): Actor}>
     */
    public static function actorKinds(): iterable
    {
        yield 'user' => [static fn (UserId $userId): Actor => Actor::user($userId)];
        yield 'agent' => [static fn (UserId $userId): Actor => Actor::agent($userId, self::AGENT_NAME)];
        yield 'system' => [static fn (): Actor => Actor::system()];
    }

    /**
     * @param \Closure(UserId): Actor $createActor
     */
    #[DataProvider('actorKinds')]
    #[TestDox('All actor kinds reload unchanged')]
    public function testAllActorKindsReloadUnchanged(\Closure $createActor): void
    {
        $actor = $createActor($this->userId);
        $review = ReviewData::review(ReviewData::gitHubRepository($this->tenantId), $actor);
        $review->close(ReviewData::time('2026-09-30T16:30:00Z'), $actor, null);
        $request = ReviewData::request($review, $actor);
        $comment = new Comment(CommentId::generate(), $request, $actor, 'Noted.', false, null, ReviewData::time());
        $event = ReviewEvent::record(ReviewData::recordedEvent($this->tenantId, $review->getId(), 1, new ReviewClosed(null), $actor));
        self::store($review->getRepository(), $review, $request, $comment, $event);

        $reloadedReview = self::reload($review);
        $expected = ActorCodec::encode($actor);
        self::assertSame(
            ['startedBy' => $expected, 'closedBy' => $expected, 'author' => $expected, 'comment' => $expected, 'event' => $expected],
            [
                'startedBy' => EntitySnapshot::actor($reloadedReview->getStartedBy()),
                'closedBy' => EntitySnapshot::actor($reloadedReview->getClosedBy()),
                'author' => EntitySnapshot::actor(self::reload($request)->getAuthor()),
                'comment' => EntitySnapshot::actor(self::reload($comment)->getAuthor()),
                'event' => EntitySnapshot::actor(self::reload($event)->getActor()),
            ],
        );
    }

    /**
     * Two of each entity: one with every nullable column set, one with the defaults.
     *
     * @return list<object>
     */
    private function everyEntityWithEveryColumnSet(): array
    {
        $user = Actor::user($this->userId);
        $agent = Actor::agent($this->userId, self::AGENT_NAME);

        $gitHubRepository = ReviewData::gitHubRepository($this->tenantId);
        $gitHubRepository->changeSync(SyncState::Idle, ReviewData::time('2026-09-29T07:35:00Z'), 'fatal: unable to access');
        $gitHubRepository->changeDefaultBranch(RefName::fromString('main'), CommitSha::fromString(str_repeat('0123456789abcdef', 4)));
        $localRepository = ReviewData::localRepository($this->tenantId);
        $localRepository->removeAccess();

        $closedReview = ReviewData::review($gitHubRepository, $user);
        $closedReview->changeIndexStatus(IndexState::Failed, 120, 60, 345, ReviewData::time('2026-09-29T07:31:00Z'), ReviewData::time('2026-09-29T07:32:00Z'), 'Syntax error in src/Broken.php');
        $closedReview->close(ReviewData::time('2026-09-30T16:30:00Z'), $user, 'Shipped in 2.4');
        $closedReview->changeLastEventSeq(40);
        $openReview = ReviewData::review($localRepository, $user);

        $claimedRequest = ReviewData::request($closedReview, $user);
        RequestStateMapper::apply(
            RequestState::fromParts(
                $claimedRequest->getId(),
                $closedReview->getId(),
                RequestStatus::InProgress,
                statusBeforeClaim: RequestStatus::Reopened,
                claimedBy: $agent,
                claimedAt: ReviewData::time('2026-09-29T07:40:00Z'),
                lastUserEventSeq: 5,
                lastAgentEventSeq: 7,
                lastAgentEventAsksQuestion: true,
                lastEventSeq: 8,
            ),
            $claimedRequest,
            ReviewData::time('2026-09-29T07:41:00Z'),
        );
        $newRequest = ReviewData::request($openReview, $user);

        return [
            $gitHubRepository,
            $localRepository,
            $closedReview,
            $openReview,
            $claimedRequest,
            $newRequest,
            new Comment(CommentId::generate(), $claimedRequest, $agent, 'Keep `float` here?', true, BatchId::generate(), ReviewData::time('2026-09-29T07:42:00Z')),
            new Comment(CommentId::generate(), $claimedRequest, $user, 'No.', false, null, ReviewData::time('2026-09-29T07:43:00Z')),
            CommitLink::pending($claimedRequest, CommitSha::fromString(ReviewData::OTHER_COMMIT), $agent, ReviewData::time('2026-09-29T07:44:00Z'), 9, null),
            CommitLink::available(
                $claimedRequest,
                CommitSha::fromString(ReviewData::FIX_COMMIT),
                $agent,
                ReviewData::time('2026-09-29T07:45:00Z'),
                10,
                'One refactoring covers both.',
                'Use Money for invoice totals',
                ReviewData::time('2026-09-29T07:44:10Z'),
            ),
            ...$this->oneEventOfEachType($closedReview, $claimedRequest->getId()),
        ];
    }

    /**
     * @return list<ReviewEvent>
     */
    private function oneEventOfEachType(Review $review, RequestId $requestId): array
    {
        $events = [];
        foreach (EventType::cases() as $index => $type) {
            $actor = EventType::CommitFound === $type ? Actor::system() : Actor::agent($this->userId, self::AGENT_NAME);
            $event = EventCodec::decode($type->value, EventFixtures::json($type));
            $events[] = ReviewEvent::record(ReviewData::recordedEvent($this->tenantId, $review->getId(), $index + 1, $event, $actor, $requestId));
        }

        return $events;
    }

    private function review(): Review
    {
        return ReviewData::review(ReviewData::gitHubRepository($this->tenantId), Actor::user($this->userId));
    }

    private static function symbolAnchorWithTruncatedSnapshot(): Anchor
    {
        $lines = ['<?php', '', 'namespace App\Billing;', '', 'final class InvoiceTotals', '{'];
        for ($line = 1; $line <= 250; ++$line) {
            $lines[] = \sprintf("    // Line %d: \"quoted\", \\backslash\\, tab\tend, €, 👀, </script>", $line);
        }
        $lines[] = '}';
        $range = LineRange::of(7, 256);

        return Anchor::symbol(
            RepoPath::fromString('src/Billing/InvoiceTotals.php'),
            CommitSha::fromString(ReviewData::REVIEW_COMMIT),
            BlobSha::fromString(ReviewData::BLOB),
            $range,
            SymbolRef::php(SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'),
            SnapshotCapture::capture(implode("\n", $lines)."\n", $range),
        );
    }

    private static function store(object ...$entities): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function reload(object $entity): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $reloaded = match (true) {
            $entity instanceof Repository, $entity instanceof Review, $entity instanceof ChangeRequest,
            $entity instanceof Comment, $entity instanceof ReviewEvent => $entityManager->find($entity::class, $entity->getId()->toUlid()),
            $entity instanceof CommitLink => self::getContainer()->get(CommitLinkRepository::class)->findOneBy([
                'request' => $entity->getRequestId()->toUlid(),
                'commitSha' => $entity->getCommitSha(),
            ]),
            default => throw new \LogicException('Cannot reload '.$entity::class),
        };
        self::assertInstanceOf($entity::class, $reloaded);
        self::assertNotSame($entity, $reloaded);

        return $reloaded;
    }
}
