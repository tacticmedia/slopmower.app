<?php

declare(strict_types=1);

namespace App\Projection;

use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\RequestReducer;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Review;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps `change_request`, `comment`, and `commit_link` from the thread entries of each request. Review events and
 * batch.submitted change none of them.
 */
final class RequestProjector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChangeRequestRepository $requests,
        private readonly CommitLinkRepository $links,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;

        if ($event instanceof RequestOpenedEvent) {
            $this->opened($recorded);
        } elseif ($event instanceof CommitLinkedEvent) {
            foreach ($event->requestIds as $requestId) {
                [$request] = $this->apply($requestId, $recorded);
                $link = new CommitLink($request, $event->commitSha, $recorded->actor, $recorded->occurredAt, $event->note);

                if (null !== $event->subject && null !== $event->committedAt) {
                    $link->found($recorded->occurredAt, $event->subject, $event->committedAt);
                }

                $this->entityManager->persist($link);
            }
        } elseif ($event instanceof CommitUnlinkedEvent) {
            [, $links] = $this->apply($event->requestId, $recorded);
            $this->entityManager->remove(self::link($links, $event->commitSha));
        } elseif ($event instanceof CommitFoundEvent) {
            foreach ($event->requestIds as $requestId) {
                [, $links] = $this->apply($requestId, $recorded);
                self::link($links, $event->commitSha)->found($recorded->occurredAt, $event->subject, $event->committedAt);
            }
        } elseif (null !== $recorded->requestId) {
            [$request] = $this->apply($recorded->requestId, $recorded);

            if ($event instanceof RequestCommentedEvent) {
                $this->entityManager->persist(new Comment($event->commentId, $request, $recorded->actor, $event->body, $event->question, $event->batchId, $recorded->occurredAt));
            }
        }
    }

    /**
     * Inserts the row, or recomputes it in place during a projection rebuild.
     */
    private function opened(RecordedEvent $recorded): void
    {
        $state = RequestReducer::apply(null, $recorded);
        $request = $this->requests->findFresh($state->id);

        if (null === $request) {
            $review = $this->entityManager->getReference(Review::class, $state->reviewId)
                ?? throw new \LogicException("The review {$state->reviewId} has no row.");
            $this->entityManager->persist(RequestStateMapper::toEntity($review, $state));

            return;
        }

        $request->opened($state->anchor, $state->body, $state->author, $state->createdAt, $state->carriedFrom, $state->requireApproval);
        RequestStateMapper::update($request, $state);
    }

    /**
     * @return array{ChangeRequest, list<CommitLink>} The request and its links before the event
     */
    private function apply(RequestId $requestId, RecordedEvent $recorded): array
    {
        $request = $this->requests->findFresh($requestId) ?? throw new \LogicException("The request {$requestId} has no row.");
        $links = $this->links->findFreshOf($request);
        RequestStateMapper::update($request, RequestReducer::apply(RequestStateMapper::toState($request, $links), $recorded));

        return [$request, $links];
    }

    /**
     * @param list<CommitLink> $links
     */
    private static function link(array $links, CommitSha $commitSha): CommitLink
    {
        foreach ($links as $link) {
            if ($link->getCommitSha()->equals($commitSha)) {
                return $link;
            }
        }

        throw new \LogicException("The commit {$commitSha} has no link row.");
    }
}
