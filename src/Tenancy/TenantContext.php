<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Service\ResetInterface;

final class TenantContext implements ResetInterface
{
    private ?TenantId $tenantId = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function set(TenantId $tenantId): void
    {
        $this->tenantId = $tenantId;
        // The ulid column type is a native uuid on PostgreSQL, and SQLFilter::getParameter() ignores the parameter type.
        $this->filter()->setParameter(TenantSqlFilter::PARAMETER, Ulid::fromString($tenantId->toString())->toRfc4122());
    }

    /**
     * Until {@see self::reset()}. {@see self::set()} keeps the restriction, so a message that `sync://` handles inside
     * the request stays in the scope.
     */
    public function restrictToRepository(RepositoryId $repositoryId): void
    {
        $this->entityManager->getFilters()
            ->enable(RepositoryScopeSqlFilter::NAME)
            ->setParameter(RepositoryScopeSqlFilter::PARAMETER, Ulid::fromString($repositoryId->toString())->toRfc4122());
    }

    public function get(): TenantId
    {
        return $this->tenantId ?? throw new MissingTenantContext();
    }

    public function current(): ?TenantId
    {
        return $this->tenantId;
    }

    /**
     * Between worker requests DoctrineBundle only clears an open entity manager, so its filter keeps the parameter.
     * A new filter instance has none.
     */
    public function reset(): void
    {
        $this->tenantId = null;
        $filters = $this->entityManager->getFilters();

        if ($filters->isEnabled(TenantSqlFilter::NAME)) {
            $filters->disable(TenantSqlFilter::NAME);
        }

        $filters->enable(TenantSqlFilter::NAME);

        if ($filters->isEnabled(RepositoryScopeSqlFilter::NAME)) {
            $filters->disable(RepositoryScopeSqlFilter::NAME);
        }
    }

    /**
     * A tenant set inside {@see TenantFilter::suspend()} applies after the callable.
     */
    private function filter(): SQLFilter
    {
        $filters = $this->entityManager->getFilters();

        return $filters->isSuspended(TenantSqlFilter::NAME)
            ? $filters->getSuspendedFilters()[TenantSqlFilter::NAME]
            : $filters->enable(TenantSqlFilter::NAME);
    }
}
