<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Actor\Actor;
use App\Domain\Event\CommitFound;
use App\Domain\Event\CommitLinked;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimed;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecordedEventTest extends KernelTestCase
{
    use ViolatedFields;

    private const string ULID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';

    #[TestDox('Carries the envelope fields and the event')]
    public function testCarriesTheEnvelopeFieldsAndTheEvent(): void
    {
        $requestId = RequestId::fromString(self::ULID);
        $actor = Actor::agent(UserId::fromString(self::ULID), 'Claude Code');
        $occurredAt = new \DateTimeImmutable('2026-09-29T10:11:12Z');
        $event = new RequestClaimed();

        $recorded = self::recordedEvent(seq: 1, requestId: $requestId, actor: $actor, occurredAt: $occurredAt, event: $event);

        self::assertSame(
            [1, $requestId, EventType::RequestClaimed, $actor, $occurredAt, $event],
            [$recorded->seq, $recorded->requestId, $recorded->type, $recorded->actor, $recorded->occurredAt, $recorded->event],
        );
    }

    /**
     * @return iterable<string, array{int, EventType, bool, list<string>}>
     */
    public static function invalidEnvelopes(): iterable
    {
        yield 'seq 0' => [0, EventType::RequestClaimed, true, ['seq']];
        yield 'negative seq' => [-1, EventType::RequestClaimed, true, ['seq']];
        yield 'type of another request event' => [1, EventType::RequestReleased, true, ['type']];
        yield 'request event without a requestId' => [1, EventType::RequestClaimed, false, ['requestId']];
        yield 'review type with a requestId' => [1, EventType::ReviewStarted, true, ['type', 'requestId']];
        yield 'everything wrong' => [0, EventType::RequestOpened, false, ['seq', 'type', 'requestId']];
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidEnvelopes')]
    #[TestDox('Rejects a seq below 1, a type other than the type of the event, and a requestId that breaks the rule of the type')]
    public function testRejectsAnInvalidEnvelope(int $seq, EventType $type, bool $hasRequestId, array $expectedFields): void
    {
        $requestId = $hasRequestId ? RequestId::fromString(self::ULID) : null;

        self::assertSame($expectedFields, self::violatedFields(static fn (): RecordedEvent => self::recordedEvent(seq: $seq, requestId: $requestId, type: $type)));
    }

    #[TestDox('Requires the system actor for commit.found')]
    public function testRequiresTheSystemActorForCommitFound(): void
    {
        $event = CommitFound::of(
            CommitSha::fromString('e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e'),
            [RequestId::fromString(self::ULID)],
            'Extract the tax rounding into TaxRounding',
            new \DateTimeImmutable('2026-09-29T07:52:30Z'),
        );
        $userId = UserId::fromString(self::ULID);
        $system = Actor::system();

        self::assertSame($system, self::recordedEvent(type: EventType::CommitFound, actor: $system, event: $event)->actor);
        foreach ([Actor::user($userId), Actor::agent($userId, 'Claude Code')] as $actor) {
            self::assertSame(['actor'], self::violatedFields(static fn (): RecordedEvent => self::recordedEvent(type: EventType::CommitFound, actor: $actor, event: $event)));
        }
    }

    #[TestDox('Accepts an agent actor for commit.linked and commit.unlinked')]
    public function testAcceptsAnAgentActorForCommitLinkedAndCommitUnlinked(): void
    {
        $commitSha = CommitSha::fromString('e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e');
        $requestId = RequestId::fromString(self::ULID);
        $agent = Actor::agent(UserId::fromString(self::ULID), 'Claude Code');

        foreach ([CommitLinked::pending($commitSha, [$requestId], null), new CommitUnlinked($commitSha, $requestId)] as $event) {
            self::assertSame($agent, self::recordedEvent(type: $event::type(), actor: $agent, event: $event)->actor);
        }
    }

    private static function recordedEvent(
        int $seq = 1,
        ?RequestId $requestId = null,
        EventType $type = EventType::RequestClaimed,
        ?Actor $actor = null,
        ?\DateTimeImmutable $occurredAt = null,
        DomainEvent $event = new RequestClaimed(),
    ): RecordedEvent {
        return new RecordedEvent(
            EventId::fromString(self::ULID),
            TenantId::fromString(self::ULID),
            $seq,
            ReviewId::fromString(self::ULID),
            $requestId,
            $type,
            $actor ?? Actor::system(),
            $occurredAt ?? new \DateTimeImmutable('2026-09-29T10:11:12Z'),
            $event,
        );
    }
}
