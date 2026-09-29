<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Actor;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Identity\UserId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActorTest extends KernelTestCase
{
    use ViolatedFields;

    private const string USER_ID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';
    private const string OTHER_USER_ID = '01K6B3P2A9QRSTVWXYZ0123456';

    /**
     * @return iterable<string, array{ActorKind, bool, ?string, list<string>}>
     */
    public static function invalidParts(): iterable
    {
        yield 'user without userId' => [ActorKind::User, false, null, ['actor.userId']];
        yield 'user with agentName' => [ActorKind::User, true, 'bot', ['actor.agentName']];
        yield 'agent without agentName' => [ActorKind::Agent, true, null, ['actor.agentName']];
        yield 'system with userId' => [ActorKind::System, true, null, ['actor.userId']];
        yield 'system with agentName' => [ActorKind::System, false, 'bot', ['actor.agentName']];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validAgentNames(): iterable
    {
        yield 'one character' => ['a'];
        yield '64 characters' => [str_repeat('a', 64)];
        yield 'every punctuation character' => ['ci-bot_1.2@host:8080 (x)'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAgentNames(): iterable
    {
        yield 'empty' => [''];
        yield '65 characters' => [str_repeat('a', 65)];
        yield 'leading space' => [' bot'];
        yield 'slash' => ['ci/bot'];
        yield 'trailing newline' => ["bot\n"];
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidParts')]
    #[TestDox('Enforces userId and agentName rules per kind')]
    public function testEnforcesUserIdAndAgentNameRulesPerKind(ActorKind $kind, bool $hasUserId, ?string $agentName, array $expectedFields): void
    {
        $userId = $hasUserId ? UserId::fromString(self::USER_ID) : null;

        self::assertSame($expectedFields, self::violatedFields(static fn (): Actor => Actor::fromParts($kind, $userId, $agentName)));
    }

    #[TestDox('Builds a user, an agent, and the system with the fields of their kind')]
    public function testBuildsEachKindWithItsFields(): void
    {
        $userId = UserId::fromString(self::USER_ID);

        $user = Actor::user($userId);
        $agent = Actor::agent($userId, 'Claude Code');
        $system = Actor::system();

        self::assertSame([ActorKind::User, $userId, null], [$user->kind, $user->userId, $user->agentName]);
        self::assertSame([ActorKind::Agent, $userId, 'Claude Code'], [$agent->kind, $agent->userId, $agent->agentName]);
        self::assertSame([ActorKind::System, null, null], [$system->kind, $system->userId, $system->agentName]);
    }

    #[TestDox('Rebuilds a valid stored actor of each kind')]
    public function testRebuildsAValidStoredActorOfEachKind(): void
    {
        $userId = UserId::fromString(self::USER_ID);

        self::assertTrue(Actor::fromParts(ActorKind::User, $userId, null)->equals(Actor::user($userId)));
        self::assertTrue(Actor::fromParts(ActorKind::Agent, $userId, 'Claude Code')->equals(Actor::agent($userId, 'Claude Code')));
        self::assertTrue(Actor::fromParts(ActorKind::System, null, null)->equals(Actor::system()));
    }

    #[DataProvider('validAgentNames')]
    #[TestDox('Accepts an agent name that follows the API token name rules')]
    public function testAcceptsAnAgentNameThatFollowsTheApiTokenNameRules(string $agentName): void
    {
        self::assertSame($agentName, Actor::agent(UserId::fromString(self::USER_ID), $agentName)->agentName);
    }

    #[DataProvider('invalidAgentNames')]
    #[TestDox('Rejects an agent name that breaks the API token name rules')]
    public function testRejectsAnAgentNameThatBreaksTheApiTokenNameRules(string $agentName): void
    {
        $userId = UserId::fromString(self::USER_ID);

        self::assertSame(['actor.agentName'], self::violatedFields(static fn (): Actor => Actor::agent($userId, $agentName)));
    }

    #[TestDox('Equals an actor of the same kind, user, and agent name only')]
    public function testEqualsAnActorOfTheSameKindUserAndAgentNameOnly(): void
    {
        $userId = UserId::fromString(self::USER_ID);
        $otherUserId = UserId::fromString(self::OTHER_USER_ID);

        self::assertTrue(Actor::user($userId)->equals(Actor::user(UserId::fromString(self::USER_ID))));
        self::assertFalse(Actor::user($userId)->equals(Actor::user($otherUserId)));
        self::assertFalse(Actor::user($userId)->equals(Actor::agent($userId, 'bot')));
        self::assertFalse(Actor::agent($userId, 'bot')->equals(Actor::agent($userId, 'Bot')));
        self::assertFalse(Actor::agent($userId, 'bot')->equals(Actor::agent($otherUserId, 'bot')));
        self::assertTrue(Actor::system()->equals(Actor::system()));
        self::assertFalse(Actor::system()->equals(Actor::user($userId)));
        self::assertFalse(Actor::user($userId)->equals(Actor::system()));
    }

    #[TestDox('Prefixes the violation fields with the given field')]
    public function testPrefixesTheViolationFieldsWithTheGivenField(): void
    {
        self::assertSame(
            ['closedBy.userId', 'closedBy.agentName'],
            self::violatedFields(static fn (): Actor => Actor::fromParts(ActorKind::System, UserId::fromString(self::USER_ID), 'bot', 'closedBy')),
        );
    }
}
