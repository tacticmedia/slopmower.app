<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Actor\Actor;
use App\Domain\Event\Codec\ActorCodec;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\EventCodec;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\ReviewEvent;

/**
 * Every getter value of an entity as scalars, so assertSame() also catches a changed type or a lost fraction of a second.
 */
final class EntitySnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(object $entity): array
    {
        return match (true) {
            $entity instanceof Repository => self::repository($entity),
            $entity instanceof Review => self::review($entity),
            $entity instanceof ChangeRequest => self::request($entity),
            $entity instanceof Comment => self::comment($entity),
            $entity instanceof CommitLink => self::commitLink($entity),
            $entity instanceof ReviewEvent => self::reviewEvent($entity),
            default => throw new \LogicException('No snapshot for '.$entity::class),
        };
    }

    public static function time(?\DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @return array{kind: string, userId: string|null, agentName: string|null}|null
     */
    public static function actor(?Actor $actor): ?array
    {
        return null === $actor ? null : ActorCodec::encode($actor);
    }

    /**
     * @return array<string, mixed>
     */
    private static function repository(Repository $repository): array
    {
        return [
            'id' => $repository->getId()->toString(),
            'tenantId' => $repository->getTenantId()->toString(),
            'source' => $repository->getSource()->value,
            'name' => $repository->getName()->toString(),
            'localDirectory' => $repository->getLocalDirectory(),
            'githubRepositoryId' => $repository->getGithubRepositoryId(),
            'defaultBranch' => $repository->getDefaultBranch()?->toString(),
            'defaultBranchHead' => $repository->getDefaultBranchHead()?->toString(),
            'syncState' => $repository->getSyncState()->value,
            'lastFetchedAt' => self::time($repository->getLastFetchedAt()),
            'lastError' => $repository->getLastError(),
            'access' => $repository->getAccess()->value,
            'createdAt' => self::time($repository->getCreatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function review(Review $review): array
    {
        return [
            'id' => $review->getId()->toString(),
            'tenantId' => $review->getTenantId()->toString(),
            'repositoryId' => $review->getRepositoryId()->toString(),
            'title' => $review->getTitle(),
            'ref' => $review->getRef()->toString(),
            'commitSha' => $review->getCommitSha()->toString(),
            'state' => $review->getState()->value,
            'startedBy' => self::actor($review->getStartedBy()),
            'createdAt' => self::time($review->getCreatedAt()),
            'closedAt' => self::time($review->getClosedAt()),
            'closedBy' => self::actor($review->getClosedBy()),
            'closeNote' => $review->getCloseNote(),
            'lastEventSeq' => $review->getLastEventSeq(),
            'indexState' => $review->getIndexState()->value,
            'filesTotal' => $review->getFilesTotal(),
            'filesDone' => $review->getFilesDone(),
            'symbolsTotal' => $review->getSymbolsTotal(),
            'indexStartedAt' => self::time($review->getIndexStartedAt()),
            'indexFinishedAt' => self::time($review->getIndexFinishedAt()),
            'indexError' => $review->getIndexError(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(ChangeRequest $request): array
    {
        return [
            'id' => $request->getId()->toString(),
            'tenantId' => $request->getTenantId()->toString(),
            'reviewId' => $request->getReviewId()->toString(),
            'anchor' => AnchorCodec::encode($request->getAnchor()),
            'body' => $request->getBody(),
            'author' => self::actor($request->getAuthor()),
            'createdAt' => self::time($request->getCreatedAt()),
            'status' => $request->getStatus()->value,
            'statusBeforeClaim' => $request->getStatusBeforeClaim()?->value,
            'claimedBy' => self::actor($request->getClaimedBy()),
            'claimedAt' => self::time($request->getClaimedAt()),
            'wontDoSetBy' => $request->getWontDoSetBy()?->value,
            'verifiedFrom' => $request->getVerifiedFrom()?->value,
            'lastUserEventSeq' => $request->getLastUserEventSeq(),
            'lastAgentEventSeq' => $request->getLastAgentEventSeq(),
            'lastAgentEventAsksQuestion' => $request->lastAgentEventAsksQuestion(),
            'awaiting' => $request->getAwaiting()->value,
            'resolution' => $request->getResolution()?->value,
            'lastEventSeq' => $request->getLastEventSeq(),
            'updatedAt' => self::time($request->getUpdatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function comment(Comment $comment): array
    {
        return [
            'id' => $comment->getId()->toString(),
            'tenantId' => $comment->getTenantId()->toString(),
            'requestId' => $comment->getRequestId()->toString(),
            'author' => self::actor($comment->getAuthor()),
            'body' => $comment->getBody(),
            'question' => $comment->isQuestion(),
            'batchId' => $comment->getBatchId()?->toString(),
            'createdAt' => self::time($comment->getCreatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commitLink(CommitLink $commitLink): array
    {
        return [
            'tenantId' => $commitLink->getTenantId()->toString(),
            'requestId' => $commitLink->getRequestId()->toString(),
            'commitSha' => $commitLink->getCommitSha()->toString(),
            'state' => $commitLink->getState()->value,
            'linkedBy' => self::actor($commitLink->getLinkedBy()),
            'linkedAt' => self::time($commitLink->getLinkedAt()),
            'linkedSeq' => $commitLink->getLinkedSeq(),
            'note' => $commitLink->getNote(),
            'foundAt' => self::time($commitLink->getFoundAt()),
            'subject' => $commitLink->getSubject(),
            'committedAt' => self::time($commitLink->getCommittedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reviewEvent(ReviewEvent $reviewEvent): array
    {
        $recorded = $reviewEvent->toRecordedEvent();

        return [
            'id' => $reviewEvent->getId()->toString(),
            'tenantId' => $reviewEvent->getTenantId()->toString(),
            'seq' => $reviewEvent->getSeq(),
            'reviewId' => $reviewEvent->getReviewId()->toString(),
            'requestId' => $reviewEvent->getRequestId()?->toString(),
            'requestIds' => array_map(strval(...), $reviewEvent->getRequestIds()),
            'type' => $reviewEvent->getType()->value,
            'actor' => self::actor($reviewEvent->getActor()),
            'occurredAt' => self::time($reviewEvent->getOccurredAt()),
            'payload' => EventCodec::encode($recorded->event),
            'recordedEvent' => [
                $recorded->id->toString(),
                $recorded->tenantId->toString(),
                $recorded->seq,
                $recorded->reviewId->toString(),
                $recorded->requestId?->toString(),
                $recorded->type->value,
                self::actor($recorded->actor),
                self::time($recorded->occurredAt),
            ],
        ];
    }
}
