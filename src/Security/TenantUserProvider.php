<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\UserId;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads a session user by its user ID without a tenant context, then sets the tenant context of that user.
 *
 * @implements UserProviderInterface<SessionUser>
 */
final class TenantUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantFilter $tenantFilter,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SessionUser
    {
        try {
            $userId = UserId::fromString($identifier);
        } catch (InvalidInput $exception) {
            throw self::notFound($identifier, $exception);
        }

        $user = $this->tenantFilter->suspend(function () use ($userId): ?SessionUser {
            $entity = $this->entityManager->find(User::class, $userId->toUlid());

            return null === $entity ? null : SessionUser::fromUser($entity);
        });
        if (null === $user) {
            throw self::notFound($identifier);
        }

        // set() needs the enabled filter, so it cannot run inside the suspend() callable.
        $this->tenantContext->set($user->tenantId());

        return $user;
    }

    public function refreshUser(UserInterface $user): SessionUser
    {
        if (!$user instanceof SessionUser) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SessionUser::class === $class;
    }

    private static function notFound(string $identifier, ?\Throwable $previous = null): UserNotFoundException
    {
        $exception = new UserNotFoundException(\sprintf('No user has the ID "%s".', $identifier), 0, $previous);
        $exception->setUserIdentifier($identifier);

        return $exception;
    }
}
