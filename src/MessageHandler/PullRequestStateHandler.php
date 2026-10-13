<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Git\CommitSha;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositorySource;
use App\Entity\PullRequest;
use App\Entity\Repository;
use App\GitHub\GitHubRequestFailed;
use App\GitHub\InstallationTokenProvider;
use App\GitHub\Payload\PullRequestPayload;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Message\GitHubPullRequestJob;
use App\Message\ReadPullRequestJob;
use App\Repository\ChangeRequestRepository;
use App\Repository\GitHubInstallationRepository;
use App\Repository\PullRequestRepository;
use App\Repository\RepositoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * docs/specs/webhooks-and-live-updates.md, "Pull request state". A job that GitHub cannot answer is skipped, not
 * failed: a repository without access, and an installation that is suspended or has not accepted Pull requests: read.
 */
final class PullRequestStateHandler
{
    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly InstallationTokenProvider $installationTokens,
        private readonly DenormalizerInterface $denormalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositories,
        private readonly GitHubInstallationRepository $installations,
        private readonly PullRequestRepository $pullRequests,
        private readonly ChangeRequestRepository $requests,
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
    ) {
    }

    /**
     * The record exists before the read, so a `pull_request` delivery after the installation accepts the permission
     * updates it.
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    #[AsMessageHandler]
    public function handleRead(ReadPullRequestJob $job): void
    {
        $repository = $this->repositories->findFresh($job->repositoryId);

        if (null === $repository || RepositorySource::Github !== $repository->getSource() || RepositoryAccess::Active !== $repository->getAccess()) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($repository, $job): void {
            if (null === $this->pullRequests->findForUpdate($repository->getId(), $job->number)) {
                $this->entityManager->persist(new PullRequest($repository, $job->number));
            }
        });

        $installation = $this->installations->findOneBy(['installationId' => $repository->getGithubInstallationId()]);

        if (null === $installation || $installation->isSuspended() || !$installation->canRead('pull_requests')) {
            return;
        }

        $pullRequest = $this->read($repository, $job->number);

        if (null !== $pullRequest) {
            $this->record($repository, $pullRequest);
        }
    }

    #[AsMessageHandler]
    public function handleDelivery(GitHubPullRequestJob $job): void
    {
        $repository = $this->repositories->findGitHub($job->githubRepositoryId);

        if (null !== $repository) {
            $this->record($repository, $job->pullRequest);
        }
    }

    /**
     * @return PullRequestPayload|null Null when GitHub does not find the pull request
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    private function read(Repository $repository, int $number): ?PullRequestPayload
    {
        $installationId = $repository->getGithubInstallationId() ?? throw new \LogicException('A GitHub repository has an installation ID.');
        $token = $this->installationTokens->forRepository($installationId, (int) $repository->getGithubRepositoryId(), InstallationTokenProvider::PULL_REQUESTS_READ);
        $path = "repos/{$repository->getName()}/pulls/{$number}";
        $response = $this->githubClient->request('GET', InstallationTokenProvider::API.$path, ['auth_bearer' => $token, 'headers' => InstallationTokenProvider::API_HEADERS]);

        if (404 === $response->getStatusCode()) {
            return null;
        }

        try {
            $pullRequest = $this->denormalizer->denormalize($response->toArray(), PullRequestPayload::class);
        } catch (SerializerException $invalid) {
            throw new GitHubRequestFailed("GitHub answered GET /{$path} with an object of an unexpected shape.", 0, $invalid);
        }

        return $number === $pullRequest->number ? $pullRequest : throw new GitHubRequestFailed("GitHub answered GET /{$path} with another pull request.");
    }

    /**
     * Publishes after the commit, so a view that reloads reads the new state.
     */
    private function record(Repository $repository, PullRequestPayload $pullRequest): void
    {
        $headSha = CommitSha::fromString($pullRequest->headSha, 'head.sha');
        $changed = $this->entityManager->wrapInTransaction(fn (): bool => $this->pullRequests->findForUpdate($repository->getId(), $pullRequest->number)?->record(
            $pullRequest->title,
            $pullRequest->state,
            $pullRequest->merged(),
            $pullRequest->draft,
            $headSha,
            $pullRequest->headRepositoryFullName,
            $pullRequest->updatedAt,
        ) ?? false);

        if (!$changed) {
            return;
        }

        $reviews = [];
        $requestIds = [];

        foreach ($this->requests->findLinkingPullRequest($repository->getId(), $pullRequest->number) as $request) {
            $reviewId = $request->getReview()->getId()->toString();
            $reviews[$reviewId] = $request->getReview();
            $requestIds[$reviewId][] = $request->getId()->toString();
        }

        foreach ($reviews as $reviewId => $review) {
            $this->publisher->publish($this->topics->review($review->getTenantId(), $review->getId()), [
                'type' => 'pull_request',
                'reviewId' => $reviewId,
                'requestIds' => $requestIds[$reviewId],
            ]);
        }
    }
}
