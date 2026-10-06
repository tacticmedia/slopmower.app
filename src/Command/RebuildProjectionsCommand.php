<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use App\EventStore\CommandExecutor;
use App\Repository\TenantRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:projections:rebuild', description: 'Rebuilds the projections from the event log, one tenant at a time')]
final class RebuildProjectionsCommand
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantFilter $tenantFilter,
        private readonly TenantContext $tenantContext,
        private readonly CommandExecutor $executor,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Rebuild only this tenant ID')] ?string $tenant = null): int
    {
        try {
            $onlyTenantId = null === $tenant ? null : TenantId::fromString($tenant, '--tenant');
        } catch (InvalidInput $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $tenants = $this->tenantFilter->suspend(fn (): array => null === $onlyTenantId
            ? $this->tenants->findBy([], ['id' => 'ASC'])
            : array_filter([$this->tenants->find($onlyTenantId)]));

        if (null !== $onlyTenantId && [] === $tenants) {
            $io->error("No tenant has the ID {$tenant}.");

            return Command::FAILURE;
        }

        $tenantIds = array_map(static fn (Tenant $tenant): TenantId => $tenant->getId(), $tenants);

        foreach ($tenantIds as $tenantId) {
            $this->tenantContext->set($tenantId);
            $io->writeln("Tenant {$tenantId}: {$this->executor->rebuildProjections()} events replayed.");
        }

        $io->success('The projections are rebuilt.');

        return Command::SUCCESS;
    }
}
