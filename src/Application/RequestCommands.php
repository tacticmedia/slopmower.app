<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\LinkCommitsResult;
use App\Application\Query\RequestQuery;
use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Git\CommitInfo;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\CommitsNotAvailable;
use App\Domain\Request\InvalidTransition;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Review\ReviewSnapshot;
use App\Entity\Draft;
use App\EventStore\CommandExecutor;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;
use App\Repository\DraftRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Each method but linkCommits() returns the events that the command wrote. The ID of an opened request is in the
 * requestId of its event. Git work runs outside the event transaction.
 */
final class RequestCommands
{
    /** Requests during the delay reuse the queued fetch, so this is the debounce per repository. */
    public const int FETCH_DELAY_MILLISECONDS = 5000;

    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReviewRepository $reviews,
        private readonly ChangeRequestRepository $requests,
        private readonly CommitLinkRepository $links,
        private readonly DraftRepository $drafts,
        private readonly RequestQuery $requestQuery,
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly PinRefs $pins,
        private readonly TenantDataPaths $paths,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RecordedEvent>
     */
    public function open(ReviewId $reviewId, Open $command, Actor $actor): array
    {
        return $this->run($reviewId, $command, $actor);
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
     * D5 of docs/specs/rest-api-requests.md section 5: in one transaction, request.opened or request.commented per draft
     * of the user in the review, sorted by createdAt, then batch.submitted. Deletes exactly the drafts that it read, so a draft created meanwhile stays.
     *
     * @return list<RecordedEvent> batch.submitted last
     *
     * @throws NotFound
     * @throws InvalidInput No drafts
     * @throws ReviewClosed
     */
    public function submitDrafts(ReviewId $reviewId, ?string $summary, Actor $actor): array
    {
        $userId = $actor->userId ?? throw new \LogicException('Only a user submits drafts.');
        $batchId = BatchId::generate();
        /** @var list<Draft> $drafts */
        $drafts = [];

        return $this->executor->execute(
            $reviewId,
            $actor,
            function () use ($reviewId, $userId, $batchId, $summary, $actor, &$drafts): array {
                $review = ($this->reviews->findFresh($reviewId) ?? throw new NotFound())->toSnapshot();
                $drafts = $this->drafts->findOfUser($userId, $reviewId);

                if ([] === $drafts) {
                    throw InvalidInput::field('', 'There are no drafts to submit.');
                }

                $decided = array_map(fn (Draft $draft): DecidedEvent => $this->decideDraft($draft, $batchId, $review, $actor), $drafts);

                return [...$decided, new DecidedEvent(self::batchSubmitted($batchId, $summary, $decided))];
            },
            function () use (&$drafts): void {
                foreach ($drafts as $draft) {
                    $this->entityManager->remove($draft);
                }
            },
        );
    }

    /**
     * @param list<CommitInfo> $availability
     *
     * @return list<RecordedEvent>
     */
    private function run(ReviewId $reviewId, RequestCommand $command, Actor $actor, array $availability = []): array
    {
        return $this->executor->execute($reviewId, $actor, function () use ($reviewId, $command, $actor, $availability): array {
            $review = $this->reviews->findFresh($reviewId) ?? throw new NotFound();
            $states = array_map($this->state(...), $command->requestIds());

            return RequestDecider::decide($command, $states, $review->toSnapshot(), $actor, $availability);
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

    private function decideDraft(Draft $draft, BatchId $batchId, ReviewSnapshot $review, Actor $actor): DecidedEvent
    {
        $request = $draft->getRequest();
        $command = null === $request
            ? new Open($draft->getAnchor() ?? throw new \LogicException('A new request draft has an anchor.'), $draft->getBody(), $batchId)
            : new Reply($request->getId(), $draft->getBody(), batchId: $batchId);

        return RequestDecider::decide($command, array_map($this->state(...), $command->requestIds()), $review, $actor)[0]
            ?? throw new \LogicException('A draft decides one event.');
    }

    /**
     * @param list<DecidedEvent> $decided The request events of the batch
     */
    private static function batchSubmitted(BatchId $batchId, ?string $summary, array $decided): BatchSubmittedEvent
    {
        $requestIds = [];
        $commentIds = [];

        foreach ($decided as $event) {
            $requestId = $event->requestId ?? throw new \LogicException('A draft decides a request event.');
            $requestIds[$requestId->toString()] ??= $requestId;

            if ($event->event instanceof RequestCommentedEvent) {
                $commentIds[] = $event->event->commentId;
            }
        }

        return new BatchSubmittedEvent($batchId, $summary, array_values($requestIds), $commentIds);
    }
}
