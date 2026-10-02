<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Repository\UserRepository;
use App\Security\LocalSignInAuthenticator;
use App\Security\LocalUserProvisioner;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LocalSignInSupportsTest extends KernelTestCase
{
    #[TestDox('supports() is true only in dev, with a non-empty login, for POST /login/local')]
    #[DataProvider('requests')]
    public function testSupportsOnlyTheLocalSignInPostInDev(string $environment, string $login, string $method, string $path, bool $expected): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $provisioner = new LocalUserProvisioner(
            $entityManager,
            new UserRepository(self::createStub(ManagerRegistry::class)),
            new TenantFilter($entityManager),
            new MockClock(),
        );
        $authenticator = new LocalSignInAuthenticator($environment, $login, $provisioner, self::createStub(UrlGeneratorInterface::class), new NullLogger());

        self::assertSame($expected, $authenticator->supports(Request::create($path, $method)));
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'dev, login, POST /login/local' => ['dev', 'jan', 'POST', '/login/local', true];
        yield 'prod environment' => ['prod', 'jan', 'POST', '/login/local', false];
        yield 'empty login' => ['dev', '', 'POST', '/login/local', false];
        yield 'GET' => ['dev', 'jan', 'GET', '/login/local', false];
        yield 'another path' => ['dev', 'jan', 'POST', '/login', false];
    }
}
