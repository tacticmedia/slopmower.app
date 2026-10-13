<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Anchoring\Locator;
use App\Application\FetchRepository;
use App\Domain\Anchor\Anchor;
use App\Domain\Error\NotFound;
use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Event\PullRequestLinkedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\Location;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositorySource;
use App\Domain\Request\CheckedRequest;
use App\Domain\Request\CheckRunOutput;
use App\Domain\Review\ReviewState;
use App\Entity\ChangeRequest;
use App\Entity\PullRequest;
use App\Entity\Repository;
use App\Entity\SignInMethod;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\GitHub\GitHubRequestFailed;
use App\GitHub\InstallationTokenProvider;
use App\Message\CheckRunJob;
use App\Message\NeutralCheckRunJob;
use App\Repository\ChangeRequestRepository;
use App\Repository\GitHubInstallationRepository;
use App\Repository\PullRequestRepository;
use App\Repository\RepositoryRepository;
use App\Repository\ReviewEventRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * docs/specs/webhooks-and-live-updates.md, "Check run". A job that GitHub cannot answer is skipped, not failed.
 */
final class CheckRunHandler
{
    private const int MAX_ANNOTATIONS_PER_CALL = 50;

    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly InstallationTokenProvider $installationTokens,
        private readonly LockFactory $lockFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly PullRequestRepository $pullRequests,
        private readonly RepositoryRepository $repositories,
        private readonly GitHubInstallationRepository $installations,
        private readonly ChangeRequestRepository $requests,
        private readonly ReviewEventRepository $events,
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly Locator $locator,
        private readonly UserRepository $users,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The lock of the record holds for the whole job, so an older job cannot write after a newer one.
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    #[AsMessageHandler]
    public function handle(CheckRunJob $job): void
    {
        $lock = $this->lockFactory->createLock("check-run:{$job->pullRequestId}", ttl: null);
        $lock->acquire(true);

        try {
            $this->check($job);
        } finally {
            $lock->release();
        }
    }

    /**
     * With `checkEveryPullRequest`, a pull request that the tenant does not link gets a neutral run, so a required
     * check does not block it. A record of the number, or an open record on the head, has the run of a link, which the
     * neutral run must not override. The check runs under the installation lock, so a record whose run came meanwhile
     * counts.
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    #[AsMessageHandler]
    public function handleNeutral(NeutralCheckRunJob $job): void
    {
        $repository = $this->repositories->findFresh($job->repositoryId);
        $installationId = null === $repository ? null : $this->writableInstallation($repository);
        $name = $this->checkName();

        if (null === $repository || null === $installationId || null === $name || !$repository->checksEveryPullRequest()) {
            return;
        }

        $output = CheckRunOutput::of([]);
        $this->inSeries($installationId, function () use ($repository, $installationId, $job, $output, $name): void {
            if ($this->pullRequests->hasNumberOrOpenHead($repository->getId(), $job->number, $job->headSha)) {
                return;
            }

            $token = $this->installationTokens->forRepository($installationId, (int) $repository->getGithubRepositoryId(), InstallationTokenProvider::CHECKS_WRITE);
            $this->send('POST', "repos/{$repository->getName()}/check-runs", $token, [
                'name' => $name,
                'head_sha' => $job->headSha->toString(),
                'external_id' => CheckRunOutput::tenantExternalId($repository->getTenantId()),
                'status' => $output->status,
                'conclusion' => $output->conclusion,
                'output' => ['title' => $output->title, 'summary' => $output->summary],
            ]);
        });
    }

    /**
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    private function check(CheckRunJob $job): void
    {
        $this->pullRequests->clearCheckRunRequest($job->pullRequestId);
        $pullRequest = $this->pullRequests->findFresh($job->pullRequestId);
        $headSha = $pullRequest?->getHeadSha();
        $repository = $pullRequest?->getRepository();
        $installationId = null === $repository ? null : $this->writableInstallation($repository);
        $name = $this->checkName();

        if (null === $pullRequest || null === $headSha || null === $repository || null === $installationId || null === $name || !$pullRequest->isOpen()) {
            return;
        }

        $requests = $this->requests->findLinkingPullRequest($repository->getId(), $pullRequest->getNumber());
        $fromFork = $this->fromFork($pullRequest, $repository);
        $headInClone = $this->headInClone($repository, $headSha, $fromFork);
        $output = CheckRunOutput::of(
            $this->checkedRequests($repository, $requests, $headInClone ? $headSha : null),
            $headInClone ? null : self::missingHeadReason($headSha, $fromFork),
        );
        $detailsUrl = $this->detailsUrl($pullRequest, $requests);
        $this->inSeries($installationId, fn () => $this->write($job, $pullRequest, $repository, $installationId, $name, $headSha, $output, $detailsUrl));
    }

    /**
     * The check of the tenant's GitHub user, see {@see CheckRunOutput::name()}.
     */
    private function checkName(): ?string
    {
        $user = $this->users->findOneBy(['signInMethod' => SignInMethod::GitHub]);

        return null === $user ? null : CheckRunOutput::name($user->getLogin());
    }

    /**
     * @return int|null The installation ID, null when GitHub cannot take a check run of the repository
     */
    private function writableInstallation(Repository $repository): ?int
    {
        $installationId = $repository->getGithubInstallationId();

        if (RepositorySource::Github !== $repository->getSource() || RepositoryAccess::Active !== $repository->getAccess() || null === $installationId) {
            return null;
        }

        $installation = $this->installations->findOneBy(['installationId' => $installationId]);
        $writable = null !== $installation && !$installation->isSuspended() && $installation->accepts(InstallationTokenProvider::CHECKS_WRITE);

        return $writable ? $installationId : null;
    }

    /**
     * The calls of one installation go in series.
     *
     * @param \Closure(): void $calls
     */
    private function inSeries(int $installationId, \Closure $calls): void
    {
        $lock = $this->lockFactory->createLock("github-installation:{$installationId}", ttl: null);
        $lock->acquire(true);

        try {
            $calls();
        } finally {
            $lock->release();
        }
    }

    /**
     * Records a new run after its last call, so a failed job leaves the previous run in the record.
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    private function write(CheckRunJob $job, PullRequest $pullRequest, Repository $repository, int $installationId, string $name, CommitSha $headSha, CheckRunOutput $output, ?string $detailsUrl): void
    {
        $now = $this->clock->now();
        $runToUpdate = $job->newRun ? null : $this->pullRequests->findFresh($pullRequest->getId())?->checkRunToUpdate($headSha, $output->hash($name), $now);
        $token = $this->installationTokens->forRepository($installationId, (int) $repository->getGithubRepositoryId(), InstallationTokenProvider::CHECKS_WRITE);
        $runs = "repos/{$repository->getName()}/check-runs";
        $state = array_filter(['details_url' => $detailsUrl, 'status' => $output->status, 'conclusion' => $output->conclusion], static fn (?string $value): bool => null !== $value);
        $text = ['title' => $output->title, 'summary' => $output->summary];

        if (null !== $runToUpdate) {
            $this->send('PATCH', "{$runs}/{$runToUpdate}", $token, [...$state, 'output' => $text]);

            return;
        }

        $groups = array_chunk($output->annotations, self::MAX_ANNOTATIONS_PER_CALL);
        $created = ['name' => $name, 'head_sha' => $headSha->toString(), 'external_id' => $pullRequest->getId()->toString(), ...$state, 'output' => [...$text, 'annotations' => $groups[0] ?? []]];
        $runId = $this->send('POST', $runs, $token, $created);

        foreach (\array_slice($groups, 1) as $annotations) {
            $this->send('PATCH', "{$runs}/{$runId}", $token, ['output' => [...$text, 'annotations' => $annotations]]);
        }

        $this->entityManager->wrapInTransaction(fn () => $this->pullRequests->findForUpdate($repository->getId(), $pullRequest->getNumber())?->recordCheckRun($runId, $headSha, $output->hash($name), $now));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return int The ID of the check run
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    private function send(string $method, string $path, string $token, array $body): int
    {
        $response = $this->githubClient->request($method, InstallationTokenProvider::API.$path, ['auth_bearer' => $token, 'headers' => InstallationTokenProvider::API_HEADERS, 'json' => $body]);
        $id = $response->toArray()['id'] ?? null;

        return \is_int($id) ? $id : throw new GitHubRequestFailed("GitHub answered {$method} /{$path} without a check run ID.");
    }

    /**
     * Fetches once when the clone lacks the head. A fetch brings no head of a fork, and a rate-limited fetch counts as
     * a missing head.
     */
    private function headInClone(Repository $repository, CommitSha $headSha, bool $fromFork): bool
    {
        try {
            $gitDir = $this->fetchRepository->readyClonePath($repository->getId());

            if ($this->reader->hasCommit($gitDir, $headSha)) {
                return true;
            }

            if ($fromFork) {
                return false;
            }

            $this->fetchRepository->fetchNow($repository->getId());

            return $this->reader->hasCommit($gitDir, $headSha);
        } catch (NotFound|RepositoryUnavailable|RateLimited) {
            return false;
        }
    }

    /**
     * A deleted fork has no head repository.
     */
    private function fromFork(PullRequest $pullRequest, Repository $repository): bool
    {
        $headRepository = $pullRequest->getHeadRepositoryFullName();

        return null === $headRepository || 0 !== strcasecmp($headRepository, $repository->getName()->toString());
    }

    private static function missingHeadReason(CommitSha $headSha, bool $fromFork): string
    {
        $source = $fromFork ? 'The pull request comes from a fork, and Slopmower fetches only the branches of the repository.' : 'The fetch did not bring it.';

        return "No annotations: the head commit {$headSha} is not in the clone of Slopmower. {$source}";
    }

    /**
     * @param list<ChangeRequest> $requests
     *
     * @return list<CheckedRequest>
     */
    private function checkedRequests(Repository $repository, array $requests, ?CommitSha $headSha): array
    {
        $openedEventIds = [];
        $checked = [];

        foreach ($requests as $request) {
            $review = $request->getReview();
            $reviewId = $review->getId();
            $anchor = $request->getAnchor();
            $unlocated = new CheckedRequest(
                $reviewId,
                $review->getTitle(),
                $this->reviewUrl($reviewId),
                ReviewState::Closed === $review->getState(),
                $this->urlGenerator->generate('shell_request', ['id' => $request->getId()->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
                $request->getBody(),
                $request->getStatus(),
                $request->getWontDoBy(),
                null === $anchor,
                null,
            );

            if (null === $anchor || null === $headSha || $unlocated->finished()) {
                $checked[] = $unlocated;

                continue;
            }

            $openedEventIds[$reviewId->toString()] ??= $this->events->findOpenedEventIds($reviewId);
            $openedEventId = $openedEventIds[$reviewId->toString()][$request->getId()->toString()] ?? null;
            $checked[] = $unlocated->at(null === $openedEventId ? null : $this->locate($repository, $request->getId(), $openedEventId, $anchor, $headSha));
        }

        return $checked;
    }

    /**
     * A failure gives no location, so one request that git cannot locate does not stop the check run.
     */
    private function locate(Repository $repository, RequestId $requestId, EventId $openedEventId, Anchor $anchor, CommitSha $headSha): ?Location
    {
        try {
            return $this->locator->locate($repository, $openedEventId, $anchor, $headSha);
        } catch (GitFailed $failure) {
            $this->logger->error('The location of the request {requestId} failed.', ['requestId' => $requestId->toString(), 'exception' => $failure]);

            return null;
        }
    }

    /**
     * The review of the newest `pull_request.linked` event that still links the number.
     *
     * @param list<ChangeRequest> $requests The requests that link the pull request
     */
    private function detailsUrl(PullRequest $pullRequest, array $requests): ?string
    {
        $linking = array_map(static fn (ChangeRequest $request): string => $request->getId()->toString(), $requests);
        $reviewIds = [];

        foreach ($requests as $request) {
            $reviewIds[$request->getReview()->getId()->toString()] = $request->getReview()->getId();
        }

        foreach ([] === $reviewIds ? [] : $this->events->findPullRequestLinks(array_values($reviewIds)) as $stored) {
            $recorded = $stored->toRecordedEvent();
            $event = $recorded->event;
            $stillLinked = $event instanceof PullRequestLinkedEvent && $event->url->gitHubNumber() === $pullRequest->getNumber()
                && array_any($event->requestIds, static fn (RequestId $id): bool => \in_array($id->toString(), $linking, true));

            if ($stillLinked) {
                return $this->reviewUrl($recorded->reviewId);
            }
        }

        return null;
    }

    private function reviewUrl(ReviewId $reviewId): string
    {
        return $this->urlGenerator->generate('shell_review_requests', ['id' => $reviewId->toString()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
