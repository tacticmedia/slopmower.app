<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Actor\Actor;
use App\Domain\Event\Codec\ActorCodec;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActorCodecTest extends KernelTestCase
{
    private const string USER_ID = '01K6AZZ9Y8X7W6V5T4S3R2Q1P0';

    /**
     * @param array{kind: string, userId: string|null, agentName: string|null} $encoded
     */
    #[TestDox('Round-trips an agent and the system actor')]
    #[DataProvider('actors')]
    public function testRoundTrips(Actor $actor, array $encoded): void
    {
        self::assertSame($encoded, ActorCodec::encode($actor));
        self::assertEquals($actor, ActorCodec::decode(PayloadReader::of($encoded)));
    }

    /**
     * @return iterable<string, array{Actor, array{kind: string, userId: string|null, agentName: string|null}}>
     */
    public static function actors(): iterable
    {
        $userId = UserId::fromString(self::USER_ID);

        yield 'agent' => [Actor::agent($userId, 'claude-code laptop'), ['kind' => 'agent', 'userId' => self::USER_ID, 'agentName' => 'claude-code laptop']];
        yield 'system' => [Actor::system(), ['kind' => 'system', 'userId' => null, 'agentName' => null]];
    }
}
