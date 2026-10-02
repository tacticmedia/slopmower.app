<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Identity\ApiTokenId;
use App\Domain\Identity\UserId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The caller of a bearer request: actor `agent`. Lives only for one request on a stateless firewall.
 */
final readonly class AgentUser implements UserInterface
{
    public const string ROLE = 'ROLE_AGENT';

    /**
     * @param string $agentName The token name
     */
    public function __construct(
        public ApiTokenId $tokenId,
        public UserId $userId,
        public string $agentName,
        public AccessScope $scope,
    ) {
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    /**
     * The token ID, never the secret.
     */
    public function getUserIdentifier(): string
    {
        $id = $this->tokenId->toString();
        \assert('' !== $id);

        return $id;
    }
}
