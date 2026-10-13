<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\LinkCommitsResult;
use App\Application\Query\LinkPullRequestResult;
use App\Application\Query\RequestQuery;
use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\LimitExceeded;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitInfo;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\RepositorySource;
use App\Domain\Request\Command\ApproveProposal;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\LinkPullRequest;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\ProposeSolution;
use App\Domain\Request\Command\RejectProposal;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\SetPlanRequirement;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\UnlinkPullRequest;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\CommitsNotAvailable;
use App\Domain\Request\InvalidTransition;
use App\Domain\Request\PullRequestUrl;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\EventStore\CommandExecutor;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Message\ReadPullRequestJob;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;
use App\Repository\ReviewRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Each method but linkCommits() and linkPullRequest() returns the events that the command wrote. The ID of an opened
 * request is in the requestId of its event. Git work runs outside the event transaction.
 */
final class RequestCommands
{
    /** Requests during the delay reuse the queued fetch, so this is the debounce per repository. */
    public const int FETCH_DELAY_MILLISECONDS = 5000;

    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly ReviewRepository $reviews,
        private readonly ChangeRequestRepository $requests,
        private readonly CommitLinkRepository $links,
        private readonly RequestQuery $requestQuery,
        private readonly FetchRepository $fetchRepository,
        private readonly UsageLimits $usageLimits,
        private readonly GitReader $reader,
        private readonly PinRefs $pins,
        private readonly TenantDataPaths $paths,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RecordedEvent>
     *
     * @throws LimitExceeded The requests of the review
     */
    public function open(ReviewId $reviewId, Open $command, Actor $actor): array
    {
        return $this->run($reviewId, $command, $actor, openedRequests: 1);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function reply(Reply $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent> Empty for a repeated claim by the holder
     */
    public function claim(Claim $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function release(Release $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * Q7. A commit that the server clone has is pinned before the transaction. A missing one stays pending and queues
     * a fetch, which {@see ResolvePendingCommitLinks} follows.
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition
     */
    public function linkCommits(LinkCommits $command, Actor $actor): LinkCommitsResult
    {
        $commitSha = $command->commitSha;
        $first = $command->requestIds[0] ?? throw InvalidInput::field('requestIds', 'Must not be empty.');
        $review = ($this->requests->findFresh($first) ?? throw new NotFound())->getReview();
        $repository = $review->getRepository();
        $gitDir = $this->paths->clonePath($repository->getTenantId(), $repository->getId());
        $commit = $this->reader->hasCommit($gitDir, $commitSha) ? $this->reader->commitInfo($gitDir, $commitSha) : null;

        if (null !== $commit) {
            $this->pins->pinCommit($gitDir, $commitSha);
        }

        try {
            $events = $this->run($review->getId(), $command, $actor, null === $commit ? [] : [$commit]);
        } catch (\Throwable $failure) {
            if (null !== $commit && !$this->links->isLinkedInRepository($repository->getId(), $commitSha)) {
                $this->pins->unpinCommit($gitDir, $commitSha);
            }

            throw $failure;
        }

        if (null === $commit) {
            $this->requestFetch($repository->getId());
        }

        return $this->requestQuery->linkCommitsResult($commitSha, null === $commit ? CommitLinkState::Pending : CommitLinkState::Available, $command->requestIds, $events);
    }

    /**
     * Q17. The command carries the name of the review's repository, so the decider checks a GitHub URL against it and
     * stores its canonical form. A new link of a GitHub pull request queues the read of its state.
     *
     * @param list<RequestId> $requestIds
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition
     */
    public function linkPullRequest(string $url, array $requestIds, ?string $note, Actor $actor): LinkPullRequestResult
    {
        $first = $requestIds[0] ?? throw InvalidInput::field('requestIds', 'Must not be empty.');
        $review = ($this->requests->findFresh($first) ?? throw new NotFound())->getReview();
        $repository = $review->getRepository();
        $events = $this->run($review->getId(), new LinkPullRequest($url, $requestIds, $repository->getName(), $note), $actor);
        $linked = PullRequestUrl::forRepository($url, $repository->getName());
        $number = $linked->gitHubNumber();

        if ([] !== $events && RepositorySource::Github === $repository->getSource() && null !== $number) {
            $this->bus->dispatch(new ReadPullRequestJob($repository->getId(), $number));
        }

        return $this->requestQuery->linkPullRequestResult($linked, $requestIds, $events);
    }

    /**
     * Q19. The command carries the name of the review's repository, as in {@see self::linkPullRequest()}.
     *
     * @return list<RecordedEvent>
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition
     */
    public function unlinkPullRequest(RequestId $requestId, string $url, Actor $actor): array
    {
        $review = ($this->requests->find($requestId) ?? throw new NotFound())->getReview();

        return $this->run($review->getId(), new UnlinkPullRequest($requestId, $url, $review->getRepository()->getName()), $actor);
    }

    /**
     * Q8. Deletes the pin of the commit after the transaction, when no other link in the repository names it.
     *
     * @return list<RecordedEvent>
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition
     */
    public function unlinkCommit(UnlinkCommit $command, Actor $actor): array
    {
        $events = $this->run($this->reviewOf($command->requestId), $command, $actor);
        $repository = ($this->requests->findFresh($command->requestId) ?? throw new NotFound())->getReview()->getRepository();

        if (!$this->links->isLinkedInRepository($repository->getId(), $command->commitSha)) {
            try {
                $this->pins->unpinCommit($this->paths->clonePath($repository->getTenantId(), $repository->getId()), $command->commitSha);
            } catch (GitFailed $failure) {
                // The unlink is committed. A removed repository has no clone left.
                $this->logger->warning('The pin of commit {commitSha} could not be deleted.', ['commitSha' => $command->commitSha->toString(), 'exception' => $failure]);
            }
        }

        return $events;
    }

    /**
     * Q10. Pending links get one synchronous fetch, during which {@see ResolvePendingCommitLinks} writes commit.found in
     * its own transaction. The command then reads the links again.
     *
     * @return list<RecordedEvent>
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition|CommitsNotAvailable|RepositoryUnavailable
     */
    public function markReadyForReview(MarkReadyForReview $command, Actor $actor): array
    {
        $reviewId = $this->reviewOf($command->requestId);

        try {
            // The decider checks pending links last, so every other refusal comes before any fetch.
            return $this->run($reviewId, $command, $actor);
        } catch (CommitsNotAvailable) {
            $request = $this->requests->findFresh($command->requestId) ?? throw new NotFound();
            $this->fetchRepository->fetchNow($request->getReview()->getRepository()->getId());
        }

        return $this->run($reviewId, $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function markWontDo(MarkWontDo $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function verify(Verify $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function reopen(Reopen $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function propose(ProposeSolution $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function approveProposal(ApproveProposal $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function rejectProposal(RejectProposal $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @return list<RecordedEvent> Empty when the value does not change
     */
    public function setPlanRequirement(SetPlanRequirement $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @param list<CommitInfo> $availability
     *
     * @return list<RecordedEvent>
     */
    private function run(ReviewId $reviewId, RequestCommand $command, Actor $actor, array $availability = [], int $openedRequests = 0): array
    {
        return $this->executor->execute($reviewId, $actor, function () use ($reviewId, $command, $actor, $availability, $openedRequests): array {
            $review = $this->reviews->findFresh($reviewId) ?? throw new NotFound();
            $states = array_map($this->state(...), $command->requestIds());
            $events = RequestDecider::decide($command, $states, $review->toSnapshot(), $actor, $availability);
            $this->usageLimits->checkNewRequests($review->getTenantId(), $reviewId, $openedRequests);

            return $events;
        });
    }

    /**
     * The review of a request never changes, so this read needs neither the lock nor a fresh row.
     */
    private function reviewOf(RequestId $requestId): ReviewId
    {
        return ($this->requests->find($requestId) ?? throw new NotFound())->getReview()->getId();
    }

    private function state(RequestId $requestId): RequestState
    {
        $request = $this->requests->findFresh($requestId) ?? throw new NotFound();

        return RequestStateMapper::toState($request, $this->links->findFreshOf($request));
    }

    private function requestFetch(RepositoryId $repositoryId): void
    {
        try {
            $this->fetchRepository->request($repositoryId, self::FETCH_DELAY_MILLISECONDS);
        } catch (RepositoryUnavailable) {
            // The access is removed. The link stays pending, and Q10 answers repository_unavailable.
        }
    }
}
