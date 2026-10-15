<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\LimitExceeded;
use App\Domain\Error\NotFound;
use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use App\Repository\RepositoryRepository;
use App\Source\LocalRepositoryDiscovery;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class ConnectLocalRepository
{
    public function __construct(
        private readonly LocalRepositoryDiscovery $discovery,
        private readonly RepositoryRepository $repositories,
        private readonly FetchRepository $fetchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly GitHubRepositoriesLock $repositoriesLock,
        private readonly UsageLimits $usageLimits,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * R3 with `source: local`. A new repository is `cloning`, and its clone job is queued after the row commits.
     *
     * @throws InvalidInput  Local repositories are off, or the name is not a direct child name
     * @throws NotFound      The directory is not a local candidate, or the tenant is being deleted
     * @throws LimitExceeded
     */
    public function connect(string $directory): ConnectedRepository
    {
        if (!$this->discovery->isEnabled()) {
            throw InvalidInput::field('source', 'Local repositories are off on this instance.');
        }

        $name = RepositoryName::local($directory, 'directory');
        $this->discovery->find($directory) ?? throw new NotFound('The directory is not a local repository candidate.');
        $tenantId = $this->tenantContext->get();
        // A second concurrent connect waits, then finds the row, instead of failing on the unique index.
        $connected = $this->repositoriesLock->run($tenantId, function () use ($tenantId, $name, $directory): ConnectedRepository {
            $existing = $this->repositories->findLocal($directory);

            if (null !== $existing) {
                return new ConnectedRepository($existing, false);
            }

            $this->usageLimits->checkNewRepository($tenantId);
            $repository = Repository::local($tenantId, $name, $directory, $this->clock->now());
            $this->entityManager->persist($repository);

            return new ConnectedRepository($repository, true);
        }) ?? throw new NotFound();

        if ($connected->created) {
            $this->fetchRepository->queueClone($connected->repository);
        }

        return $connected;
    }
}
