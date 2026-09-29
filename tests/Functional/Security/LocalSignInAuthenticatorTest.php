<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\LocalSignIn;
use App\Security\LocalSignInAuthenticator;
use App\Security\LocalUserProvisioner;
use App\Tests\Support\Database\ResetDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The route exists only in dev, so the tests build the authenticator with a dev {@see LocalSignIn}.
 */
final class LocalSignInAuthenticatorTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('The passport carries the authenticate CSRF token, success redirects to the saved target path or to /login, and failure keeps the error for /login')]
    public function testSuccessAndFailureRedirects(): void
    {
        $authenticator = self::authenticator();
        $request = Request::create('/login/local', 'POST', ['_csrf_token' => 'csrf-token']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $passport = $authenticator->authenticate($request);

        $csrfBadge = $passport->getBadge(CsrfTokenBadge::class);
        self::assertInstanceOf(CsrfTokenBadge::class, $csrfBadge);
        self::assertSame('authenticate', $csrfBadge->getCsrfTokenId());
        self::assertSame('csrf-token', $csrfBadge->getCsrfToken());
        $token = $authenticator->createToken($passport, 'main');

        self::assertRedirectsTo('/login', $authenticator->onAuthenticationSuccess($request, $token, 'main'));
        $request->getSession()->set('_security.main.target_path', '/settings');
        self::assertRedirectsTo('/settings', $authenticator->onAuthenticationSuccess($request, $token, 'main'));

        $error = new InvalidCsrfTokenException();
        self::assertRedirectsTo('/login', $authenticator->onAuthenticationFailure($request, $error));
        self::assertSame($error, $request->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
    }

    private static function authenticator(): LocalSignInAuthenticator
    {
        $container = self::getContainer();

        return new LocalSignInAuthenticator(
            new LocalSignIn('dev', 'jan'),
            $container->get(LocalUserProvisioner::class),
            $container->get(UrlGeneratorInterface::class),
            new NullLogger(),
        );
    }

    private static function assertRedirectsTo(string $expectedUrl, mixed $response): void
    {
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($expectedUrl, $response->getTargetUrl());
    }
}
