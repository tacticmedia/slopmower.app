<?php

declare(strict_types=1);

namespace App\Security;

use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * The session stores the authenticated token, and an OAuthToken carries the GitHub user token. The server stores no user token.
 * A LoginSuccessEvent listener still finds it in the OAuthTokenBadge of the passport.
 */
#[AsEventListener(event: AuthenticationTokenCreatedEvent::class, dispatcher: 'security.event_dispatcher.'.self::FIREWALL)]
final class DropOAuthTokenListener
{
    private const string FIREWALL = 'main';

    public function __invoke(AuthenticationTokenCreatedEvent $event): void
    {
        $token = $event->getAuthenticatedToken();
        $user = $token->getUser();

        if ($token instanceof OAuthToken && $user instanceof UserInterface) {
            $event->setAuthenticatedToken(new PostAuthenticationToken($user, self::FIREWALL, $token->getRoleNames()));
        }
    }
}
