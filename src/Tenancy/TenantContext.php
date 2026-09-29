<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Public, because the compiler removes a private service that no other service uses, also from the test container.
 */
#[Autoconfigure(public: true)]
final class TenantContext implements ResetInterface
{
    private ?TenantId $tenantId = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws TenantMismatch when another tenant is set. Call {@see self::reset()} first.
     */
    public function set(TenantId $tenantId): void
    {
        if (null !== $this->tenantId && !$this->tenantId->equals($tenantId)) {
            throw TenantMismatch::contextAlreadySet();
        }

        // SQLFilter::getParameter() quotes the value without a type conversion, and the column is a PostgreSQL uuid.
        $databaseValue = Type::getType(UlidType::NAME)->convertToDatabaseValue(
            $tenantId->toUlid(),
            $this->entityManager->getConnection()->getDatabasePlatform(),
        );
        $this->entityManager->getFilters()->getFilter(TenantSqlFilter::NAME)
            ->setParameter(TenantSqlFilter::PARAMETER, $databaseValue, UlidType::NAME);
        $this->tenantId = $tenantId;
        $this->detachOtherTenants();
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw MissingTenantContext::notSet();
    }

    /**
     * EntityManager::find() answers from the identity map without the SQL filter.
     * Entities scheduled for a write stay, so {@see TenantWriteGuard} rejects the flush.
     */
    public function detachOtherTenants(): void
    {
        $tenantId = $this->tenantId;
        if (null === $tenantId) {
            return;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($unitOfWork->isUninitializedObject($entity) || $unitOfWork->isEntityScheduled($entity)) {
                    continue;
                }
                $ownerTenantId = TenantOwnership::tenantIdOf($entity);
                if (null !== $ownerTenantId && !$ownerTenantId->equals($tenantId)) {
                    $this->entityManager->detach($entity);
                }
            }
        }
    }

    /**
     * Between worker requests DoctrineBundle only clears an open entity manager, so the filter keeps its parameter.
     * A new filter instance has none. The entity manager is cleared here too, so a manual reset() leaves no entity behind.
     */
    public function reset(): void
    {
        $this->tenantId = null;
        $this->entityManager->clear();

        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(TenantSqlFilter::NAME)) {
            $filters->disable(TenantSqlFilter::NAME);
        }
        $filters->enable(TenantSqlFilter::NAME);
    }
}
