<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Domain\Identity\TenantId;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantFilterSuspendTest extends KernelTestCase
{
    use ResetDatabase;

    private TenantFilter $tenantFilter;
    private TenantId $bobsTenantId;

    protected function setUp(): void
    {
        $alice = TenantFixtures::of(self::getContainer())->withLogin('alice')->create();
        $this->bobsTenantId = TenantFixtures::of(self::getContainer())->withLogin('bob')->create()->getTenantId();
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());
        $this->tenantFilter = self::getContainer()->get(TenantFilter::class);
    }

    #[TestDox('Inside the callable, results cross tenants')]
    public function testCallableSeesEveryTenant(): void
    {
        self::assertSame(['alice', 'bob'], $this->tenantFilter->suspend(self::logins(...)));
    }

    #[TestDox('The filter is on again with the same tenant after the callable, also when the callable throws')]
    public function testFilterIsRestoredAfterTheCallable(): void
    {
        $this->tenantFilter->suspend(self::logins(...));
        self::assertSame(['alice'], self::logins());

        try {
            $this->tenantFilter->suspend(self::throwingCallable(...));
            self::fail('suspend() swallowed the exception of the callable.');
        } catch (\DomainException) {
        }
        self::assertSame(['alice'], self::logins());
    }

    #[TestDox('A nested suspend works, and the filter is on again after the outer callable')]
    public function testNestedSuspend(): void
    {
        $tenantFilter = $this->tenantFilter;
        $logins = $tenantFilter->suspend(static fn (): array => [$tenantFilter->suspend(self::logins(...)), self::logins()]);

        self::assertSame([['alice', 'bob'], ['alice', 'bob']], $logins);
        self::assertSame(['alice'], self::logins());
    }

    #[TestDox('A tenant set inside the callable applies after the callable')]
    public function testTenantSetInsideTheCallableAppliesAfterIt(): void
    {
        $this->tenantFilter->suspend(function (): void {
            self::getContainer()->get(TenantContext::class)->set($this->bobsTenantId);
        });

        self::assertSame(['bob'], self::logins());
    }

    private static function throwingCallable(): bool
    {
        throw new \DomainException('Callable failed.');
    }

    /**
     * @return list<string>
     */
    private static function logins(): array
    {
        $users = self::getContainer()->get(UserRepository::class)->findBy([], ['login' => 'ASC']);

        return array_map(static fn (User $user): string => $user->getLogin(), $users);
    }
}
