<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Ulid;

/**
 * @implements UserProviderInterface<User>
 */
final class TenantUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TenantFilter $tenantFilter,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): User
    {
        $user = Ulid::isValid($identifier)
            ? $this->tenantFilter->suspend(fn (): ?User => $this->users->find(Ulid::fromString($identifier)))
            : null;

        if (null === $user) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        $this->tenantContext->set($user->getTenantId());

        return $user;
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, User::class, true);
    }
}
