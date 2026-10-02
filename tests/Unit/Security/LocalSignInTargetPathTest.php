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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;

final class LocalSignInTargetPathTest extends KernelTestCase
{
    private const string TARGET_PATH_KEY = '_security.main.target_path';

    #[TestDox('A local sign-in redirects to the stored target path and removes it, else to /')]
    #[DataProvider('targetPaths')]
    public function testSuccessRedirectsToTheTargetPath(?string $storedTargetPath, string $expectedLocation): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $provisioner = new LocalUserProvisioner(
            $entityManager,
            new UserRepository(self::createStub(ManagerRegistry::class)),
            new TenantFilter($entityManager),
            new MockClock(),
        );
        $authenticator = new LocalSignInAuthenticator('dev', 'jan', $provisioner, self::createStub(UrlGeneratorInterface::class), new NullLogger());
        $session = new Session(new MockArraySessionStorage());
        if (null !== $storedTargetPath) {
            $session->set(self::TARGET_PATH_KEY, $storedTargetPath);
        }
        $request = Request::create(LocalSignInAuthenticator::PATH, 'POST');
        $request->setSession($session);

        $response = $authenticator->onAuthenticationSuccess($request, new NullToken(), 'main');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($expectedLocation, $response->getTargetUrl());
        self::assertFalse($session->has(self::TARGET_PATH_KEY));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function targetPaths(): iterable
    {
        yield 'stored target path' => ['http://localhost/reviews/01J9ZQ4R5T6V7W8X9Y0Z1A2B3C', 'http://localhost/reviews/01J9ZQ4R5T6V7W8X9Y0Z1A2B3C'];
        yield 'no target path' => [null, '/'];
    }
}
