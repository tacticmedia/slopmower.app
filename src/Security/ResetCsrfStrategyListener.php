<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * {@see \Symfony\Component\Security\Csrf\SameOriginCsrfListener} writes the CSRF strategies of the logout request, those
 * of the old session included, into the new session, which then refuses the same-origin sign-in form. 0 writes none and
 * keeps the cookie clearing of that listener.
 */
#[AsEventListener(dispatcher: 'security.event_dispatcher.main')]
final class ResetCsrfStrategyListener
{
    /** framework.csrf_protection.cookie_name, the request attribute that SameOriginCsrfListener reads. */
    private const string STRATEGY_ATTRIBUTE = 'csrf-token';

    public function __invoke(LogoutEvent $event): void
    {
        $event->getRequest()->attributes->set(self::STRATEGY_ATTRIBUTE, 0);
    }
}
