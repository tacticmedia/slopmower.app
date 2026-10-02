<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * D23. The first sign-in creates the tenant and the user.
 */
final class GitHubUserProvider implements OAuthAwareUserProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly TenantFilter $tenantFilter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function loadUserByOAuthUserResponse(UserResponseInterface $response): User
    {
        try {
            $githubUserId = filter_var($response->getUserIdentifier(), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        } catch (\InvalidArgumentException) {
            $githubUserId = false;
        }
        $login = $response->getNickname();

        if (false === $githubUserId || !\is_string($login) || '' === $login) {
            throw new AuthenticationException('GitHub returned no user.');
        }

        $name = $this->optionalString($response->getRealName());
        $avatarUrl = $this->optionalString($response->getProfilePicture());
        $now = $this->clock->now();
        $user = $this->tenantFilter->suspend(fn (): ?User => $this->users->findOneBy(['githubUserId' => $githubUserId]));

        if (null === $user) {
            $tenant = new Tenant(TenantId::generate(), $now);
            $user = User::gitHub($tenant->getId(), $githubUserId, $login, $name, $avatarUrl, $now);
            $this->entityManager->persist($tenant);
            $this->entityManager->persist($user);
        } else {
            $user->recordGitHubSignIn($login, $name, $avatarUrl, $now);
        }

        $this->entityManager->flush();

        return $user;
    }

    private function optionalString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
