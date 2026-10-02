<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Git\CommitInfo;
use App\Domain\Identity\BatchId;
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
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Review\ReviewSnapshot;
use App\Entity\Draft;
use App\EventStore\CommandExecutor;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;
use App\Repository\DraftRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Each method returns the events that the command wrote. The ID of an opened request is in the requestId of its event.
 */
final class RequestCommands
{
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReviewRepository $reviews,
        private readonly ChangeRequestRepository $requests,
        private readonly CommitLinkRepository $links,
        private readonly DraftRepository $drafts,
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
     * @param list<CommitInfo> $availability The commit, when the server clone has it
     *
     * @return list<RecordedEvent> Empty when every pair is linked already
     */
    public function linkCommits(LinkCommits $command, Actor $actor, array $availability): array
    {
        $first = $command->requestIds[0] ?? throw InvalidInput::field('requestIds', 'Must not be empty.');

        return $this->run($this->reviewOf($first), $command, $actor, $availability);
    }

    /**
     * @return list<RecordedEvent>
     */
    public function unlinkCommit(UnlinkCommit $command, Actor $actor): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor);
    }

    /**
     * @param list<CommitInfo> $availability The pending linked commits that the server clone has now
     *
     * @return list<RecordedEvent>
     */
    public function markReadyForReview(MarkReadyForReview $command, Actor $actor, array $availability = []): array
    {
        return $this->run($this->reviewOf($command->requestId), $command, $actor, $availability);
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
     * D5: in one transaction, request.opened or request.commented per draft of the user in the review, sorted by
     * createdAt, then batch.submitted. Deletes exactly the drafts that it read, so a draft created meanwhile stays.
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
        return ($this->requests->find(Ulid::fromString($requestId->toString())) ?? throw new NotFound())->getReview()->getId();
    }

    private function state(RequestId $requestId): RequestState
    {
        $request = $this->requests->findFresh($requestId) ?? throw new NotFound();

        return RequestStateMapper::toState($request, $this->links->findFreshOf($request));
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
