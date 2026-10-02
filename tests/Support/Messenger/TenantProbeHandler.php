<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Tenancy\TenantContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class TenantProbeHandler
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantRepository $tenants,
    ) {
    }

    /**
     * @return array{context: ?string, visibleTenants: list<string>} The tenants that the SQL filter lets through
     */
    #[AsMessageHandler]
    public function probe(TenantProbe $probe): array
    {
        return [
            'context' => $this->tenantContext->current()?->toString(),
            'visibleTenants' => array_map(static fn (Tenant $tenant): string => $tenant->getId()->toString(), $this->tenants->findAll()),
        ];
    }

    #[AsMessageHandler]
    public function probeTenantless(TenantlessProbe $probe): ?string
    {
        return $this->tenantContext->current()?->toString();
    }
}
