<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Actor\Actor;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The actor of the current request: `user` for a session, `agent` for an API token.
 */
final class CurrentActor
{
    /** The 403 message of the actor-kind rule (docs/specs/README.md section 3), with #[IsGranted(User::ROLE)]. */
    public const string SESSION_ONLY = 'This endpoint needs a session. API tokens may not call it.';

    /** The 403 message of the same rule, with #[IsGranted(AgentUser::ROLE)]. */
    public const string BEARER_ONLY = 'This endpoint needs an API token. Sessions may not call it.';

    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @throws \LogicException Without an authenticated caller
     */
    public function get(): Actor
    {
        $user = $this->security->getUser();

        return match (true) {
            $user instanceof User => Actor::user($user->getId()),
            $user instanceof AgentUser => Actor::agent($user->userId, $user->agentName),
            default => throw new \LogicException('The request has no authenticated caller.'),
        };
    }
}
