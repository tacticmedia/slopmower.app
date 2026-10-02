<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

/**
 * D25. Configured in every environment, so the guard is in code, not in a `when@dev` firewall.
 */
final class LocalSignInAuthenticator extends AbstractAuthenticator
{
    public const string PATH = '/login/local';
    public const string CSRF_TOKEN_ID = 'authenticate';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire(env: 'APP_LOCAL_SIGN_IN')]
        private readonly string $login,
        private readonly LocalUserProvisioner $provisioner,
        private readonly UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger,
    ) {
        if ('dev' !== $environment && '' !== $login) {
            $logger->warning('APP_LOCAL_SIGN_IN is set in the "{environment}" environment. Local sign-in works only in dev.', ['environment' => $environment]);
        }
    }

    /**
     * Null when local sign-in is off.
     */
    public function localLogin(): ?string
    {
        return 'dev' === $this->environment && '' !== $this->login ? $this->login : null;
    }

    public function supports(Request $request): bool
    {
        return null !== $this->localLogin() && $request->isMethod('POST') && self::PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(
            new UserBadge($this->login, $this->provisioner->provision(...)),
            [new CsrfTokenBadge(self::CSRF_TOKEN_ID, $request->getPayload()->getString('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse('/');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
