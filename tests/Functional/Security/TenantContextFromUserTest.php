<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantContextFromUserTest extends WebTestCase
{
    use ResetDatabase;

    /**
     * /login reads no user, so there the listener sets the tenant.
     */
    #[TestDox('After sign-in, the filter parameter is the user\'s tenant')]
    #[DataProvider('paths')]
    public function testTenantContextComesFromTheSignedInUser(string $path): void
    {
        $client = static::createClient();
        TenantFixtures::of(self::getContainer())->withLogin('alice')->create();
        $bob = TenantFixtures::of(self::getContainer())->withLogin('bob')->create();
        $client->loginUser($bob, 'web');

        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertTrue($bob->getTenantId()->equals(self::getContainer()->get(TenantContext::class)->get()));
        $logins = array_map(static fn (User $user): string => $user->getLogin(), self::getContainer()->get(UserRepository::class)->findAll());
        self::assertSame(['bob'], $logins);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'api firewall' => ['/api/v1/me'];
        yield 'main firewall' => ['/login'];
    }
}
