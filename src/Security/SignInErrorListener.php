<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * /login shows the error of a failed sign-in. The failure handler of HWIOAuthBundle stores none.
 */
#[AsEventListener(event: LoginFailureEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class SignInErrorListener
{
    public function __invoke(LoginFailureEvent $event): void
    {
        $event->getRequest()->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $event->getException());
    }
}
