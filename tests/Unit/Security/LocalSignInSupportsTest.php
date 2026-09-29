<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\LocalSignIn;
use App\Security\LocalSignInAuthenticator;
use App\Security\LocalUserProvisioner;
use App\Security\TenantUserProvider;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LocalSignInSupportsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'dev, login set, POST /login/local' => ['dev', 'jan', 'POST', '/login/local', true];
        yield 'prod' => ['prod', 'jan', 'POST', '/login/local', false];
        yield 'dev, empty login' => ['dev', '', 'POST', '/login/local', false];
        yield 'dev, GET' => ['dev', 'jan', 'GET', '/login/local', false];
        yield 'dev, other path' => ['dev', 'jan', 'POST', '/login', false];
    }

    #[DataProvider('requests')]
    #[TestDox('supports() is true only in dev, with a non-empty login, for POST /login/local')]
    public function testSupportsOnlyDevWithLoginForPostLoginLocal(string $environment, string $login, string $method, string $path, bool $supported): void
    {
        $authenticator = self::authenticator($environment, $login, new NullLogger());

        self::assertSame($supported, $authenticator->supports(Request::create($path, $method)));
    }

    #[TestDox('Outside dev, a set APP_LOCAL_SIGN_IN logs one warning per instance, and an empty one logs none')]
    public function testLoginSetOutsideDevLogsOneWarning(): void
    {
        $logger = new BufferingLogger();
        $authenticator = self::authenticator('prod', 'jan', $logger);

        $authenticator->supports(Request::create('/login/local', 'POST'));
        $authenticator->supports(Request::create('/healthz'));

        self::assertSame([LogLevel::WARNING], array_column($logger->cleanLogs(), 0));

        self::authenticator('prod', '', $logger)->supports(Request::create('/login/local', 'POST'));
        self::authenticator('dev', 'jan', $logger)->supports(Request::create('/login/local', 'POST'));

        self::assertSame([], $logger->cleanLogs());
    }

    private static function authenticator(string $environment, string $login, LoggerInterface $logger): LocalSignInAuthenticator
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $tenantContext = new TenantContext($entityManager);
        $tenantFilter = new TenantFilter($entityManager, $tenantContext);
        $provisioner = new LocalUserProvisioner(
            $entityManager,
            $tenantFilter,
            $tenantContext,
            new TenantUserProvider($entityManager, $tenantFilter, $tenantContext),
            new MockClock(),
        );

        return new LocalSignInAuthenticator(
            new LocalSignIn($environment, $login),
            $provisioner,
            self::createStub(UrlGeneratorInterface::class),
            $logger,
        );
    }
}
