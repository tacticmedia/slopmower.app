<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\Identity\TenantId;
use App\Entity\ApiToken;
use App\Entity\User;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TokenFormatTest extends KernelTestCase
{
    #[TestDox('A secret matches ^sm_[A-Za-z0-9_-]{43}$')]
    public function testSecretFormat(): void
    {
        $secret = ApiTokenSecret::generate()->value;

        self::assertMatchesRegularExpression('/^sm_[A-Za-z0-9_-]{43}$/D', $secret);
        self::assertSame(46, strlen($secret));
    }

    #[TestDox('1,000 secrets are unique')]
    public function testSecretsAreUnique(): void
    {
        $secrets = array_map(static fn (): string => ApiTokenSecret::generate()->value, range(1, 1000));

        self::assertCount(1000, array_unique($secrets));
    }

    #[TestDox('The stored hash is the SHA-256 hex of the secret and the prefix is its first 9 characters')]
    public function testHashAndPrefix(): void
    {
        $now = new \DateTimeImmutable('2026-10-02T08:00:00Z');
        $secret = ApiTokenSecret::generate();

        $token = new ApiToken(User::local(TenantId::generate(), 'jan', $now), 'claude-code laptop', null, $secret, $now, null);

        self::assertSame(hash('sha256', $secret->value), $token->getTokenHash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $token->getTokenHash());
        self::assertSame(substr($secret->value, 0, 9), $token->getPrefix());
    }
}
