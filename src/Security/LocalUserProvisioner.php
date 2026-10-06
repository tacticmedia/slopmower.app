<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantBeingDeleted;
use App\Repository\UserRepository;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class LocalUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly TenantFilter $tenantFilter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Creates the tenant and the local user at the first sign-in.
     *
     * @throws TenantBeingDeleted
     */
    public function provision(string $login): User
    {
        $now = $this->clock->now();
        $user = $this->tenantFilter->suspend(fn (): ?User => $this->users->findLocalByLogin($login));

        if (null === $user) {
            $tenant = new Tenant(TenantId::generate(), $now);
            $user = User::local($tenant->getId(), $login, $now);
            $this->entityManager->persist($tenant);
            $this->entityManager->persist($user);
        } else {
            $user->recordSignIn($now);
        }

        $this->entityManager->flush();

        return $user;
    }
}
