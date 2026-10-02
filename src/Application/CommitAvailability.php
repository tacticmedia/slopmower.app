<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Identity\RepositoryId;
use App\Git\GitReader;
use App\Git\TenantDataPaths;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final class CommitAvailability
{
    public function __construct(
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly TenantDataPaths $paths,
    ) {
    }

    /**
     * D24: a commit missing from the server clone gets one synchronous fetch.
     *
     * @param string $field Of the InvalidInput
     *
     * @return CommitSha|null Null when the commit is still missing after the fetch
     *
     * @throws NotFound
     * @throws InvalidInput          The prefix matches more than one commit
     * @throws RepositoryUnavailable The clone is not ready, or the fetch failed
     */
    public function ensureAvailable(RepositoryId $repositoryId, CommitShaPrefix $commit, string $field = 'commit'): ?CommitSha
    {
        $repository = $this->fetchRepository->cloneReady($repositoryId);
        $gitDir = $this->paths->clonePath($repository->getTenantId(), $repositoryId);
        $available = $this->find($gitDir, $commit, $field);

        if (null !== $available) {
            return $available;
        }

        $this->fetchRepository->fetchNow($repositoryId);

        return $this->find($gitDir, $commit, $field);
    }

    private function find(string $gitDir, CommitShaPrefix $commit, string $field): ?CommitSha
    {
        try {
            return $this->reader->resolvePrefix($gitDir, $commit, $field);
        } catch (CommitNotFound) {
            return null;
        }
    }
}
