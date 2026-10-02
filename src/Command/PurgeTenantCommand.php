<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\PurgeTenant;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\TenantId;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tenant:purge', description: 'Deletes every row and file of one tenant')]
final class PurgeTenantCommand
{
    public function __construct(private readonly PurgeTenant $purgeTenant)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The tenant to delete')] string $tenantId): int
    {
        try {
            $tenant = TenantId::fromString($tenantId, 'tenantId');
        } catch (InvalidInput $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $this->purgeTenant->purge($tenant);
        $io->success("The rows and files of the tenant {$tenant} are deleted.");

        return Command::SUCCESS;
    }
}
