<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Domain\User\SignInMethod;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LocalUserProvisioner;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class LocalUserProvisioningTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetDatabase;

    #[TestDox('The first sign-in creates one tenant and one local user')]
    public function testFirstSignInCreatesOneTenantAndOneLocalUser(): void
    {
        self::mockTime('2026-09-29 07:00:00');

        $sessionUser = self::getContainer()->get(LocalUserProvisioner::class)->signIn('jan');

        self::assertSame(['tenants' => 1, 'users' => 1], self::rowCounts());
        self::assertTrue($sessionUser->tenantId()->equals(self::getContainer()->get(TenantContext::class)->tenantId()));
        $user = self::user();
        self::assertTrue($sessionUser->userId()->equals($user->getId()));
        self::assertTrue($sessionUser->tenantId()->equals($user->getTenantId()));
        self::assertSame(SignInMethod::Local, $user->getSignInMethod());
        self::assertSame('jan', $user->getLogin());
        self::assertSame('jan', $sessionUser->login());
        self::assertEquals(new \DateTimeImmutable('2026-09-29 07:00:00'), $user->getCreatedAt());
    }

    #[TestDox('A later sign-in finds the same user')]
    public function testLaterSignInFindsTheSameUser(): void
    {
        $provisioner = self::getContainer()->get(LocalUserProvisioner::class);
        self::mockTime('2026-09-29 07:00:00');
        $first = $provisioner->signIn('jan');
        self::mockTime('2026-09-30 08:00:00');

        $later = $provisioner->signIn('jan');

        self::assertSame(['tenants' => 1, 'users' => 1], self::rowCounts());
        self::assertTrue($first->userId()->equals($later->userId()));
        self::assertTrue($first->tenantId()->equals($later->tenantId()));
        $user = self::user();
        self::assertEquals(new \DateTimeImmutable('2026-09-29 07:00:00'), $user->getCreatedAt());
        self::assertEquals(new \DateTimeImmutable('2026-09-30 08:00:00'), $user->getLastSignInAt());
    }

    #[TestDox('A sign-in as another login replaces the tenant context of an earlier sign-in')]
    public function testSignInAsAnotherLoginReplacesTheTenantContext(): void
    {
        $provisioner = self::getContainer()->get(LocalUserProvisioner::class);
        $provisioner->signIn('jan');

        $bob = $provisioner->signIn('bob');

        self::assertSame(['tenants' => 2, 'users' => 2], self::rowCounts());
        self::assertTrue($bob->tenantId()->equals(self::getContainer()->get(TenantContext::class)->tenantId()));
        self::assertSame(['bob'], array_map(
            static fn (User $user): string => $user->getLogin(),
            self::getContainer()->get(UserRepository::class)->findAll(),
        ));
    }

    /**
     * @return array{tenants: mixed, users: mixed}
     */
    private static function rowCounts(): array
    {
        $connection = self::getContainer()->get(Connection::class);

        return [
            'tenants' => $connection->fetchOne('SELECT count(*) FROM tenant'),
            'users' => $connection->fetchOne("SELECT count(*) FROM app_user WHERE sign_in_method = 'local'"),
        ];
    }

    /**
     * Read through the SQL filter with the tenant context of the sign-in.
     */
    private static function user(): User
    {
        $users = self::getContainer()->get(UserRepository::class)->findAll();
        self::assertCount(1, $users);

        return $users[0];
    }
}
