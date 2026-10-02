<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Session writes pass the same-origin check (docs/specs/README.md section 3). It runs after the path ID resolvers, so
 * an invisible ID gives 404 first, and before the body is read.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class ApiCsrfListener
{
    private const string SESSION_FIREWALL = 'api';

    private const string TOKEN_ID = 'api';

    public function __construct(
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->isMethodSafe() || self::SESSION_FIREWALL !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }

        // The value of a stateless token is the cookie name, which also names the double-submit header.
        $doubleSubmitHeader = $this->csrfTokenManager->getToken(self::TOKEN_ID)->getValue();
        $token = new CsrfToken(self::TOKEN_ID, $request->headers->get($doubleSubmitHeader, $doubleSubmitHeader));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException('The request failed the same-origin check.');
        }
    }
}
