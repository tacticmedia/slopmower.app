<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\FetchRepository;
use App\Application\GitHubRepositoriesLock;
use App\Application\QueuePullRequestRefresh;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\GitHub\GitHubRequestFailed;
use App\GitHub\InstallationTokenProvider;
use App\GitHub\Payload\RepositoryPayload;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Message\GitHubInstallationCreatedJob;
use App\Message\GitHubInstallationDeletedJob;
use App\Message\GitHubInstallationPermissionsJob;
use App\Message\GitHubInstallationSuspensionJob;
use App\Message\GitHubRepositoriesAddedJob;
use App\Message\GitHubRepositoriesRemovedJob;
use App\Repository\GitHubCandidateRepository;
use App\Repository\GitHubInstallationRepository;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The `installation` and `installation_repositories` events of docs/specs/webhooks-and-live-updates.md section 1. The
 * installation and candidate rows change under the lock that the sign-in sync and the GitHub connect hold too. A job
 * for a tenant that is being deleted does nothing.
 */
final class GitHubInstallationHandler
{
    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly InstallationTokenProvider $installationTokens,
        private readonly DenormalizerInterface $denormalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly GitHubInstallationRepository $installations,
        private readonly GitHubCandidateRepository $candidates,
        private readonly RepositoryRepository $repositories,
        private readonly FetchRepository $fetchRepository,
        private readonly QueuePullRequestRefresh $queuePullRequestRefresh,
        private readonly TenantContext $tenantContext,
        private readonly GitHubRepositoriesLock $repositoriesLock,
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function handleCreated(GitHubInstallationCreatedJob $job): void
    {
        $tenantId = $this->tenantContext->get();

        $ran = $this->repositoriesLock->run($tenantId, function () use ($job, $tenantId): true {
            $installation = $this->installations->findOneBy(['installationId' => $job->installationId]);

            if (null === $installation) {
                $this->entityManager->persist(new GitHubInstallation($tenantId, $job->installationId, $job->accountId, $job->accountLogin, $job->accountType, $job->suspendedAt, $job->permissions));
            } else {
                $installation->recordPermissions($job->permissions);
            }

            return true;
        });

        if (null === $ran) {
            return;
        }

        $this->publishCandidatesChanged($tenantId);
    }

    /**
     * D22: the repositories lose their access and their clones, and their reviews stay.
     */
    #[AsMessageHandler]
    public function handleDeleted(GitHubInstallationDeletedJob $job): void
    {
        $tenantId = $this->tenantContext->get();

        $ran = $this->repositoriesLock->run($tenantId, function () use ($job): true {
            foreach ($this->candidates->findBy(['installationId' => $job->installationId]) as $candidate) {
                $this->entityManager->remove($candidate);
            }

            foreach ($this->installations->findBy(['installationId' => $job->installationId]) as $installation) {
                $this->entityManager->remove($installation);
            }

            return true;
        });

        if (null === $ran) {
            return;
        }

        $this->removeAccess(['githubInstallationId' => $job->installationId]);
        $this->publishCandidatesChanged($tenantId);
    }

    /**
     * A suspended installation gets no webhook events, so an unsuspend reads every linked pull request again.
     */
    #[AsMessageHandler]
    public function handleSuspension(GitHubInstallationSuspensionJob $job): void
    {
        $recorded = $this->repositoriesLock->run($this->tenantContext->get(), function () use ($job): bool {
            $installation = $this->installations->findOneBy(['installationId' => $job->installationId]);
            $installation?->recordSuspension($job->suspendedAt);

            return null !== $installation;
        });

        if (true === $recorded && null === $job->suspendedAt) {
            $this->queuePullRequestRefresh->ofInstallation($job->installationId, readEvery: true, checkRuns: true);
        }
    }

    /**
     * Either new permission queues the reads, because a read that sets the head SHA queues the check run.
     */
    #[AsMessageHandler]
    public function handlePermissions(GitHubInstallationPermissionsJob $job): void
    {
        $added = $this->repositoriesLock->run($this->tenantContext->get(), function () use ($job): array {
            $installation = $this->installations->findOneBy(['installationId' => $job->installationId]);

            if (null === $installation) {
                return [false, false];
            }

            $readsBefore = $installation->accepts(InstallationTokenProvider::PULL_REQUESTS_READ);
            $writesChecksBefore = $installation->accepts(InstallationTokenProvider::CHECKS_WRITE);
            $installation->recordPermissions($job->permissions);

            return [
                !$readsBefore && $installation->accepts(InstallationTokenProvider::PULL_REQUESTS_READ),
                !$writesChecksBefore && $installation->accepts(InstallationTokenProvider::CHECKS_WRITE),
            ];
        });
        [$readAdded, $checksAdded] = $added ?? [false, false];

        if ($readAdded || $checksAdded) {
            $this->queuePullRequestRefresh->ofInstallation($job->installationId, readEvery: false, checkRuns: $checksAdded);
        }
    }

    /**
     * Reads every repository before it writes, so a failed read adds none, and the retry adds all. A candidate that
     * exists stays. `listedAt` stays the time of the last sign-in, which R2 shows as `githubListedAt`.
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    #[AsMessageHandler]
    public function handleRepositoriesAdded(GitHubRepositoriesAddedJob $job): void
    {
        $tenantId = $this->tenantContext->get();
        $repositories = [];

        foreach ($job->repositories as $githubRepositoryId => $fullName) {
            $repositories[] = $this->readRepository($job->installationId, $githubRepositoryId, $fullName);
        }

        $ran = $this->repositoriesLock->run($tenantId, function () use ($job, $tenantId, $repositories): true {
            $listedAt = $this->candidates->lastListedAt() ?? $this->clock->now();

            foreach ($repositories as $repository) {
                if (null === $this->candidates->findByGitHubId($repository->id)) {
                    $this->entityManager->persist(new GitHubCandidate($tenantId, $repository->id, $job->installationId, $job->accountLogin, self::name($repository), $repository->private, $repository->defaultBranch, $repository->htmlUrl, $listedAt));
                }
            }

            return true;
        });

        if (null === $ran) {
            return;
        }

        $this->publishCandidatesChanged($tenantId);
    }

    /**
     * D22, for the listed repositories.
     */
    #[AsMessageHandler]
    public function handleRepositoriesRemoved(GitHubRepositoriesRemovedJob $job): void
    {
        $tenantId = $this->tenantContext->get();

        $ran = $this->repositoriesLock->run($tenantId, function () use ($job): true {
            foreach ($this->candidates->findBy(['githubRepositoryId' => $job->githubRepositoryIds]) as $candidate) {
                $this->entityManager->remove($candidate);
            }

            return true;
        });

        if (null === $ran) {
            return;
        }

        $this->removeAccess(['githubRepositoryId' => $job->githubRepositoryIds]);
        $this->publishCandidatesChanged($tenantId);
    }

    /**
     * After the candidates are gone, so a connect cannot add a repository that keeps its access. `removeAccess()`
     * publishes `repository` for each and queues the deletion of its clone.
     *
     * @param array<string, int|list<int>> $criteria
     */
    private function removeAccess(array $criteria): void
    {
        foreach ($this->repositories->findBy($criteria) as $repository) {
            $this->fetchRepository->removeAccess($repository);
        }
    }

    /**
     * The webhook payload has no default branch or HTML URL.
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    private function readRepository(int $installationId, int $githubRepositoryId, RepositoryName $fullName): RepositoryPayload
    {
        $token = $this->installationTokens->forRepository($installationId, $githubRepositoryId, InstallationTokenProvider::CONTENTS_READ);
        $response = $this->githubClient->request('GET', InstallationTokenProvider::API."repos/{$fullName}", ['auth_bearer' => $token, 'headers' => InstallationTokenProvider::API_HEADERS]);

        try {
            $repository = $this->denormalizer->denormalize($response->toArray(), RepositoryPayload::class);
        } catch (SerializerException $invalid) {
            throw new GitHubRequestFailed("GitHub answered GET /repos/{$fullName} with an object of an unexpected shape.", 0, $invalid);
        }

        if ($githubRepositoryId !== $repository->id) {
            throw new GitHubRequestFailed("GitHub answered GET /repos/{$fullName} with another repository.");
        }

        return $repository;
    }

    /**
     * @throws GitHubRequestFailed
     */
    private static function name(RepositoryPayload $repository): RepositoryName
    {
        try {
            return RepositoryName::github($repository->fullName);
        } catch (InvalidInput $invalid) {
            throw new GitHubRequestFailed("GitHub answered the invalid repository name {$repository->fullName}.", 0, $invalid);
        }
    }

    /**
     * After the commit.
     */
    private function publishCandidatesChanged(TenantId $tenantId): void
    {
        $this->publisher->publish($this->topics->repositories($tenantId), ['type' => 'candidates_changed']);
    }
}
