<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Event\RepositoryChanged;
use App\Entity\Repository;
use App\GitHub\InstallationSync;
use App\Message\GitHubRepositoryUpdatedJob;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The `repository` events other than `deleted`.
 */
#[AsMessageHandler]
final class GitHubRepositoryHandler
{
    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly LockFactory $lockFactory,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(GitHubRepositoryUpdatedJob $job): void
    {
        $tenantId = $this->tenantContext->get();
        $lock = $this->lockFactory->createLock(InstallationSync::lockKey($tenantId), ttl: null);
        $lock->acquire(true);

        try {
            $renamed = $this->entityManager->wrapInTransaction(fn (): ?Repository => $this->record($job));
        } finally {
            $lock->release();
        }

        if (null !== $renamed) {
            $this->dispatcher->dispatch(new RepositoryChanged($tenantId, $renamed->getId()));
        }
    }

    /**
     * @return Repository|null The repository when its name changed
     */
    private function record(GitHubRepositoryUpdatedJob $job): ?Repository
    {
        $repository = $this->repositories->findGitHub($job->githubRepositoryId);

        if (null === $repository) {
            return null;
        }

        $previousName = $repository->getName();
        $name = $job->renamed ? $this->repositories->nameAfterRename($repository, $job->fullName) : $previousName;
        $installationId = $repository->getGithubInstallationId() ?? throw new \LogicException('A GitHub repository has an installation ID.');
        $repository->recordGitHubListing($name, $installationId, $job->private);

        return $name->toString() === $previousName->toString() ? null : $repository;
    }
}
