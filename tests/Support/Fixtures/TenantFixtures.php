<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\DependencyInjection\Container;

/**
 * Builds one tenant per user inside {@see TenantFilter::suspend()}, with or without a tenant context.
 * Each call flushes and clears the entity manager,
 * so later reads come from the database through the SQL filter, not from the identity map.
 */
final readonly class TenantFixtures
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantFilter $tenantFilter,
        private ClockInterface $clock,
    ) {
    }

    public static function fromContainer(Container $container): self
    {
        \assert($container instanceof TestContainer);

        return new self(
            $container->get(EntityManagerInterface::class),
            $container->get(TenantFilter::class),
            $container->get(ClockInterface::class),
        );
    }

    public function gitHubUser(string $login, int $githubUserId): User
    {
        return $this->persistWithTenant(
            static fn (TenantId $tenantId, \DateTimeImmutable $now): User => User::gitHub(UserId::generate(), $tenantId, $githubUserId, $login, null, null, $now),
        );
    }

    public function localUser(string $login): User
    {
        return $this->persistWithTenant(
            static fn (TenantId $tenantId, \DateTimeImmutable $now): User => User::local(UserId::generate(), $tenantId, $login, $now),
        );
    }

    /**
     * @param \Closure(TenantId, \DateTimeImmutable): User $createUser
     */
    private function persistWithTenant(\Closure $createUser): User
    {
        $now = $this->clock->now();
        $tenant = new Tenant(TenantId::generate(), $now);
        $user = $createUser($tenant->getId(), $now);

        $this->tenantFilter->suspend(function () use ($tenant, $user): void {
            $this->entityManager->persist($tenant);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        });
        $this->entityManager->clear();

        return $user;
    }
}
