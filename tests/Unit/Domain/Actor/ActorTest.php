<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Actor;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Identity\UserId;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActorTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string USER_ID = '01K6AZZ9Y8X7W6V5T4S3R2Q1P0';

    /**
     * @param list<string> $expectedFields
     */
    #[TestDox('Enforces userId and agentName rules per kind')]
    #[DataProvider('invalidActors')]
    public function testEnforcesRulesPerKind(ActorKind $kind, bool $withUserId, ?string $agentName, array $expectedFields): void
    {
        $userId = $withUserId ? UserId::fromString(self::USER_ID) : null;

        self::assertInvalidInput($expectedFields, static fn () => Actor::of($kind, $userId, $agentName));
    }

    /**
     * @return iterable<string, array{ActorKind, bool, ?string, list<string>}>
     */
    public static function invalidActors(): iterable
    {
        yield 'user without userId' => [ActorKind::User, false, null, ['userId']];
        yield 'user with agentName' => [ActorKind::User, true, 'claude', ['agentName']];
        yield 'agent without userId and agentName' => [ActorKind::Agent, false, null, ['userId', 'agentName']];
        yield 'agent with an empty agentName' => [ActorKind::Agent, true, '', ['agentName']];
        yield 'agent with 65 characters' => [ActorKind::Agent, true, str_repeat('a', 65), ['agentName']];
        yield 'system with userId and agentName' => [ActorKind::System, true, 'claude', ['userId', 'agentName']];
    }

    #[TestDox('Builds a user, an agent, and the system')]
    public function testBuildsEachKind(): void
    {
        $userId = UserId::fromString(self::USER_ID);

        $agent = Actor::agent($userId, str_repeat('a', 64));

        self::assertSame(ActorKind::User, Actor::user($userId)->kind);
        self::assertSame(str_repeat('a', 64), $agent->agentName);
        self::assertNotNull($agent->userId);
        self::assertTrue($userId->equals($agent->userId));
        self::assertNull(Actor::system()->userId);
    }

    #[TestDox('Equals another actor only when kind, userId, and agentName match')]
    public function testComparesKindUserAndAgentName(): void
    {
        $userId = UserId::fromString(self::USER_ID);

        self::assertTrue(Actor::agent($userId, 'claude')->equals(Actor::agent(UserId::fromString(self::USER_ID), 'claude')));
        self::assertFalse(Actor::agent($userId, 'claude')->equals(Actor::agent($userId, 'codex')));
        self::assertFalse(Actor::agent($userId, 'claude')->equals(Actor::user($userId)));
        self::assertTrue(Actor::system()->equals(Actor::system()));
    }
}
