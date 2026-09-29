<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Domain\Identity\UserId;
use App\Security\SessionUser;
use App\Security\TenantUserProvider;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class TenantUserProviderTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('Refreshing a session user loads it without a tenant context and sets its tenant context')]
    public function testRefreshLoadsTheUserAndSetsItsTenantContext(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $fixtures->localUser('alice');
        $bob = $fixtures->gitHubUser('bob', 42);

        $refreshed = self::getContainer()->get(TenantUserProvider::class)->refreshUser(SessionUser::fromUser($bob));

        self::assertTrue($bob->getId()->equals($refreshed->userId()));
        self::assertTrue($bob->getTenantId()->equals($refreshed->tenantId()));
        self::assertSame('bob', $refreshed->login());
        self::assertTrue($bob->getTenantId()->equals(self::getContainer()->get(TenantContext::class)->tenantId()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIdentifiers(): iterable
    {
        yield 'unknown ID' => [UserId::generate()->toString()];
        yield 'malformed ID' => ['alice'];
    }

    #[DataProvider('unknownIdentifiers')]
    #[TestDox('An unknown or malformed user ID gives UserNotFoundException')]
    public function testUnknownIdGivesUserNotFound(string $identifier): void
    {
        TenantFixtures::fromContainer(self::getContainer())->localUser('alice');

        $this->expectException(UserNotFoundException::class);

        self::getContainer()->get(TenantUserProvider::class)->loadUserByIdentifier($identifier);
    }
}
