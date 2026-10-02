<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\ApiToken\ApiTokenState;
use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The token handler of the `access_token` authenticator (docs/specs/README.md section 3).
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly TenantFilter $tenantFilter,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $secret = ApiTokenSecret::tryFrom($accessToken) ?? throw new BadCredentialsException('Malformed API token.');
        $token = $this->tenantFilter->suspend(fn (): ?ApiToken => $this->tokens->findOneBy(['tokenHash' => $secret->hash()]));
        $now = $this->clock->now();

        if (null === $token || ApiTokenState::Active !== $token->state($now)) {
            throw new BadCredentialsException('Unknown, revoked, or expired API token.');
        }

        $scope = $token->getScope();
        $this->tenantContext->set($token->getTenantId());

        if (null !== $scope->repositoryId) {
            $this->tenantContext->restrictToRepository($scope->repositoryId);
        }

        if ($token->recordUse($now)) {
            $this->entityManager->flush();
        }

        $agent = new AgentUser($token->getId(), $token->getUser()->getId(), $token->getName(), $scope);

        return new UserBadge($agent->getUserIdentifier(), static fn (): AgentUser => $agent);
    }
}
