<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\User\SignInMethod;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Signs in the local user of a login. The first sign-in creates its tenant and user.
 */
final class LocalUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantFilter $tenantFilter,
        private readonly TenantContext $tenantContext,
        private readonly TenantUserProvider $userProvider,
        private readonly ClockInterface $clock,
    ) {
    }

    public function signIn(string $login): SessionUser
    {
        $userId = $this->tenantFilter->suspend(function () use ($login): UserId {
            $now = $this->clock->now();
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['signInMethod' => SignInMethod::Local, 'login' => $login]);
            if (null === $user) {
                $tenant = new Tenant(TenantId::generate(), $now);
                $user = User::local(UserId::generate(), $tenant->getId(), $login, $now);
                $this->entityManager->persist($tenant);
                $this->entityManager->persist($user);
            } else {
                $user->recordSignIn($now);
            }
            $this->entityManager->flush();

            return $user->getId();
        });

        // The sign-in replaces the identity of the request, and set() refuses a different tenant.
        $this->tenantContext->reset();

        return $this->userProvider->loadUserByIdentifier($userId->toString());
    }
}
