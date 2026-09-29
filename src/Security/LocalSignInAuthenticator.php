<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Decision D25. It is in the base firewall configuration, so {@see self::supports()} guards the environment.
 */
final class LocalSignInAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public const string PATH = '/login/local';
    public const string CSRF_TOKEN_ID = 'authenticate';
    public const string CSRF_PARAMETER = '_csrf_token';

    private bool $warnedAboutIgnoredLogin = false;

    public function __construct(
        private readonly LocalSignIn $localSignIn,
        private readonly LocalUserProvisioner $provisioner,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        if ($this->localSignIn->isSetOutsideDev()) {
            $this->warnAboutIgnoredLogin();
        }

        return null !== $this->localSignIn->login()
            && $request->isMethod(Request::METHOD_POST)
            && self::PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $login = $this->localSignIn->login() ?? throw new \LogicException('The local sign-in is off.');

        // The CSRF listener runs before the user loader, so a request without a valid token provisions nothing.
        return new SelfValidatingPassport(
            new UserBadge($login, $this->provisioner->signIn(...)),
            [new CsrfTokenBadge(self::CSRF_TOKEN_ID, $request->getPayload()->getString(self::CSRF_PARAMETER))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->getTargetPath($request->getSession(), $firewallName) ?? $this->urlGenerator->generate('login'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }

    /**
     * Once per instance, so a worker logs it once and not on every request.
     */
    private function warnAboutIgnoredLogin(): void
    {
        if ($this->warnedAboutIgnoredLogin) {
            return;
        }

        $this->logger->warning('APP_LOCAL_SIGN_IN is set, but the local sign-in works only in the dev environment. The variable is ignored.');
        $this->warnedAboutIgnoredLogin = true;
    }
}
