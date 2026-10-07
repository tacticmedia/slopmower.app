<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantBeingDeleted;
use App\Repository\UserRepository;
use App\Tenancy\TenantFilter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

final class LocalUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly UserRepository $users,
        private readonly TenantFilter $tenantFilter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Creates the tenant and the local user at the first sign-in. Of two concurrent first sign-ins, the unique index lets
     * one insert, and the other signs in as that user.
     *
     * @throws TenantBeingDeleted
     */
    public function provision(string $login): User
    {
        $now = $this->clock->now();
        $user = $this->find($login);

        if (null === $user) {
            $tenant = new Tenant(TenantId::generate(), $now);
            $user = User::local($tenant->getId(), $login, $now);
            $this->entityManager->persist($tenant);
            $this->entityManager->persist($user);

            try {
                $this->entityManager->flush();

                return $user;
            } catch (UniqueConstraintViolationException $concurrentFirstSignIn) {
                // The failed flush closed the entity manager.
                $this->doctrine->resetManager();
                $user = $this->find($login) ?? throw $concurrentFirstSignIn;
            }
        }

        $user->recordSignIn($now);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @throws TenantBeingDeleted
     */
    private function find(string $login): ?User
    {
        return $this->tenantFilter->suspend(fn (): ?User => $this->users->findLocalByLogin($login));
    }
}
