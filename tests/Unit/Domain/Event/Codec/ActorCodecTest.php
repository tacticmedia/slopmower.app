<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Actor\Actor;
use App\Domain\Event\Codec\ActorCodec;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\UserId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActorCodecTest extends KernelTestCase
{
    use ViolatedFields;

    private const string USER_ID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';

    /**
     * @return iterable<string, array{Actor, array{kind: string, userId: string|null, agentName: string|null}}>
     */
    public static function actors(): iterable
    {
        $userId = UserId::fromString(self::USER_ID);

        yield 'user' => [Actor::user($userId), ['kind' => 'user', 'userId' => self::USER_ID, 'agentName' => null]];
        yield 'agent' => [Actor::agent($userId, 'Claude Code'), ['kind' => 'agent', 'userId' => self::USER_ID, 'agentName' => 'Claude Code']];
        yield 'system' => [Actor::system(), ['kind' => 'system', 'userId' => null, 'agentName' => null]];
    }

    /**
     * @param array{kind: string, userId: string|null, agentName: string|null} $expectedEncoded
     */
    #[DataProvider('actors')]
    #[TestDox('Writes every key, with null for an absent user ID or agent name, and reads the actor back')]
    public function testRoundTripsEachKind(Actor $actor, array $expectedEncoded): void
    {
        $encoded = ActorCodec::encode($actor);

        self::assertSame($expectedEncoded, $encoded);
        self::assertTrue(ActorCodec::decode(PayloadReader::of($encoded, 'actor'))->equals($actor));
    }

    #[TestDox('Reads a lower-case user ID and a missing agent name, and writes the ID upper case')]
    public function testReadsALowerCaseUserIdAndWritesItUpperCase(): void
    {
        $actor = ActorCodec::decode(PayloadReader::of(['kind' => 'user', 'userId' => strtolower(self::USER_ID)], 'actor'));

        self::assertSame(['kind' => 'user', 'userId' => self::USER_ID, 'agentName' => null], ActorCodec::encode($actor));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function corruptActors(): iterable
    {
        yield 'missing kind' => [['userId' => self::USER_ID], ['actor.kind']];
        yield 'unknown kind' => [['kind' => 'robot', 'userId' => self::USER_ID], ['actor.kind']];
        yield 'invalid user ID' => [['kind' => 'user', 'userId' => 'x'], ['actor.userId']];
        yield 'agent name of the wrong type' => [['kind' => 'agent', 'userId' => self::USER_ID, 'agentName' => 1], ['actor.agentName']];
        yield 'agent without an agent name' => [['kind' => 'agent', 'userId' => self::USER_ID], ['actor.agentName']];
        yield 'system with a user ID' => [['kind' => 'system', 'userId' => self::USER_ID, 'agentName' => null], ['actor.userId']];
    }

    /**
     * @param array<string, mixed> $encoded
     * @param list<string>         $expectedFields
     */
    #[DataProvider('corruptActors')]
    #[TestDox('Rejects a corrupt stored actor, naming the dotted field')]
    public function testRejectsACorruptStoredActor(array $encoded, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): Actor => ActorCodec::decode(PayloadReader::of($encoded, 'actor'))));
    }
}
