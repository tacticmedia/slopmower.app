<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\TenantId;
use App\Entity\Repository;
use App\Repository\GitHubCandidateRepository;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

final class ConnectGitHubRepository
{
    public function __construct(
        private readonly GitHubCandidateRepository $candidates,
        private readonly RepositoryRepository $repositories,
        private readonly FetchRepository $fetchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The tenant lock of the GitHub repository names, installations, and candidates.
     */
    public static function lockKey(TenantId $tenantId): string
    {
        return "github-repositories:{$tenantId}";
    }

    /**
     * R3 with `source: github`. A new repository is `cloning`, and its clone job is queued after the row commits.
     *
     * @throws NotFound     The ID is not a candidate of the tenant
     * @throws InvalidInput Another repository of the tenant has the name, in any case
     */
    public function connect(int $githubRepositoryId): ConnectedRepository
    {
        $tenantId = $this->tenantContext->get();
        $lock = $this->lockFactory->createLock(self::lockKey($tenantId), ttl: null);
        $lock->acquire(true);

        try {
            $candidate = $this->candidates->findByGitHubId($githubRepositoryId) ?? throw new NotFound('The GitHub repository is not a candidate.');
            $existing = $this->repositories->findGitHub($githubRepositoryId);

            if (null !== $existing) {
                return new ConnectedRepository($existing, false);
            }

            $name = $candidate->getFullName();

            if (null !== $this->repositories->findGitHubByName($name)) {
                throw InvalidInput::field('githubRepositoryId', "Another connected repository has the name {$name}.");
            }

            $repository = Repository::gitHub($tenantId, $name, $githubRepositoryId, $candidate->getInstallationId(), $candidate->isPrivate(), $this->clock->now());
            $this->entityManager->persist($repository);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $this->fetchRepository->queueClone($repository);

        return new ConnectedRepository($repository, true);
    }
}
