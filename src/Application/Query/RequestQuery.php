<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Anchoring\Locator;
use App\Application\CommitAvailability;
use App\Application\FetchRepository;
use App\Domain\Actor\Actor;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Identity\Identifier;
use App\Domain\Identity\RequestId;
use App\Domain\Location\LocationTarget;
use App\Domain\Request\AllowedActions;
use App\Domain\Request\Awaiting;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\RequestStatus;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Review;
use App\Http\Cursor;
use App\Projection\RequestStateMapper;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Q1, Q3, and the results of the request commands. Reads refresh the rows, so a read after a command sees it.
 */
final class RequestQuery
{
    private const string ANCHOR_PATH = "JSONB_PATH_TEXT(request.anchor, 'path')";

    /** File anchors have no range, so they sort first. */
    private const string ANCHOR_START_LINE = "COALESCE(JSONB_PATH_INTEGER(request.anchor, 'range', 'startLine'), 0)";

    private const string LIKE_ESCAPE = '!';

    /** Years 1 to 9999, which TIMESTAMP(0) and the DBAL date format hold. */
    private const int EARLIEST_CURSOR_UNIX_SECONDS = -62135596800;
    private const int LATEST_CURSOR_UNIX_SECONDS = 253402300799;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ThreadQuery $threads,
        private readonly Locator $locator,
        private readonly CommitAvailability $commits,
        private readonly FetchRepository $fetchRepository,
    ) {
    }

    /**
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public function list(Review $review, RequestFilter $filter, int $limit, ?string $cursor): RequestPage
    {
        $cursorFilters = $filter->cursorFilters();
        $after = null === $cursor ? null : $this->afterKey($review, $filter->sort, Cursor::decode($cursor, $cursorFilters));
        $builder = $this->entityManager->createQueryBuilder()
            ->from(ChangeRequest::class, 'request')
            ->where('request.review = :review')
            ->setParameter('review', self::ulid($review->getId()), UlidType::NAME);
        self::filter($builder, $filter);
        $total = (int) (clone $builder)->select('COUNT(request.id)')->getQuery()->getSingleScalarResult();
        $builder->select('request');
        self::sort($builder, $filter->sort, $after);

        /** @var list<ChangeRequest> $rows */
        $rows = $builder->setMaxResults($limit + 1)->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $page = array_slice($rows, 0, $limit);
        $last = end($page);
        $nextCursor = count($rows) > $limit && false !== $last ? Cursor::encode(self::sortKey($last, $filter->sort), $cursorFilters) : null;

        return new RequestPage($review->getId(), $review->getState(), $total, $this->summaries($page), $nextCursor);
    }

    /**
     * @param CommitShaPrefix|null $at           Locates the anchor at this commit instead of the default-branch head
     * @param int|null             $contextLines Adds the excerpt with this many lines on each side
     *
     * @throws NotFound
     * @throws InvalidInput          `at` matches more than one commit
     * @throws CommitNotFound        `at` is missing after one fetch
     * @throws RepositoryUnavailable `at` needs the clone or a fetch, and neither is available
     */
    public function get(RequestId $id, Actor $caller, ?CommitShaPrefix $at = null, ?int $contextLines = null): RequestView
    {
        $request = $this->fresh($id);
        $target = $this->target($request, $at);

        // The fetch for `at` can record commit.found.
        if (null !== $at) {
            $request = $this->fresh($id);
        }

        $links = $this->linksByRequest([$request])[$id->toString()] ?? [];
        $summary = $this->summary($request, $links, $this->commentCounts([$request])[$id->toString()] ?? 0);
        $thread = $this->threads->entries($request->getReview()->getId(), $id);
        // request.opened, whose ID is the cache key of the location.
        $opened = $thread[0]->event ?? null;
        $repository = $request->getReview()->getRepository();
        $location = null !== $target && $opened?->event instanceof RequestOpenedEvent
            ? new LocationView($target[0], $this->locator->locate($repository, $opened->id, $request->getAnchor(), $target[1]))
            : null;

        return new RequestView(
            $summary,
            $thread,
            $this->commitViews($request, $links),
            AllowedActions::for($summary->state, $request->getReview()->toSnapshot(), $caller),
            $location,
            null === $location || null === $contextLines ? null : $this->locator->excerpt($repository, $location->location, $contextLines),
        );
    }

    /**
     * @param list<RecordedEvent> $events The events that the command wrote, all in the thread of the request
     *
     * @throws NotFound
     */
    public function commandResult(RequestId $id, Actor $caller, array $events): RequestCommandResult
    {
        $request = $this->fresh($id);
        [$summary] = $this->summaries([$request]);
        $last = array_last($events);
        $thread = null === $last ? [] : $this->threads->entriesBySeq($request->getReview()->getId(), $id, $last->seq);

        return new RequestCommandResult(
            $summary,
            AllowedActions::for($summary->state, $request->getReview()->toSnapshot(), $caller),
            array_map(static fn (RecordedEvent $event): EventView => ($thread[$event->seq] ?? throw new \LogicException("The event {$event->seq} is not in the thread of {$id}."))->eventView(), $events),
        );
    }

    /**
     * @param list<RequestId>     $requestIds The requests that the command named
     * @param list<RecordedEvent> $events     The commit.linked event that the command wrote, if any
     *
     * @throws NotFound
     */
    public function linkCommitsResult(CommitSha $commitSha, CommitLinkState $state, array $requestIds, array $events): LinkCommitsResult
    {
        return new LinkCommitsResult(
            $commitSha,
            $state,
            $this->summaries(array_map($this->fresh(...), $requestIds)),
            // A commit.linked event has no subject request, so its Event.statusAfter is null.
            array_map(static fn (RecordedEvent $event): EventView => new EventView($event, null), $events),
        );
    }

    /**
     * @param list<RecordedEvent> $events The events of a draft submission, batch.submitted last
     *
     * @throws NotFound
     */
    public function batchSubmission(array $events): BatchSubmission
    {
        $submitted = array_last($events);
        $batch = $submitted?->event;

        if (!$batch instanceof BatchSubmittedEvent) {
            throw new \LogicException('A draft submission ends with batch.submitted.');
        }

        $opened = [];
        $replies = [];

        foreach ($events as $event) {
            $requestId = $event->requestId;

            if ($event->event instanceof RequestOpenedEvent && null !== $requestId) {
                $opened[] = $this->fresh($requestId);
            } elseif ($event->event instanceof RequestCommentedEvent && null !== $requestId) {
                $replies[] = ['requestId' => $requestId, 'commentId' => $event->event->commentId];
            }
        }

        return new BatchSubmission($batch->batchId, $submitted->reviewId, $batch->summary, $submitted->occurredAt, $submitted->seq, $this->summaries($opened), $replies);
    }

    /**
     * Two queries for all requests: their links and their comment counts.
     *
     * @param list<ChangeRequest> $requests Loaded with a refresh
     *
     * @return list<RequestSummaryView>
     */
    public function summaries(array $requests): array
    {
        $links = $this->linksByRequest($requests);
        $commentCounts = $this->commentCounts($requests);

        return array_map(fn (ChangeRequest $request): RequestSummaryView => $this->summary(
            $request,
            $links[$request->getId()->toString()] ?? [],
            $commentCounts[$request->getId()->toString()] ?? 0,
        ), $requests);
    }

    /**
     * @return array{LocationTarget, CommitSha}|null Null without `at` when the clone is gone or has no default-branch head
     *
     * @throws InvalidInput
     * @throws CommitNotFound
     * @throws RepositoryUnavailable
     */
    private function target(ChangeRequest $request, ?CommitShaPrefix $at): ?array
    {
        $repositoryId = $request->getReview()->getRepository()->getId();

        if (null !== $at) {
            return [LocationTarget::Requested, $this->commits->ensureAvailable($repositoryId, $at, 'at') ?? throw new CommitNotFound($at->toString())];
        }

        try {
            $head = $this->fetchRepository->cloneReady($repositoryId)->getDefaultBranchHead();
        } catch (RepositoryUnavailable) {
            return null;
        }

        return null === $head ? null : [LocationTarget::DefaultBranch, $head];
    }

    /**
     * @param list<CommitLink> $links
     */
    private function summary(ChangeRequest $request, array $links, int $commentCount): RequestSummaryView
    {
        return new RequestSummaryView($request, RequestStateMapper::toState($request, $links), $commentCount);
    }

    /**
     * @throws NotFound
     */
    private function fresh(RequestId $id): ChangeRequest
    {
        $request = $this->entityManager->createQueryBuilder()
            ->select('request', 'review', 'repository')
            ->from(ChangeRequest::class, 'request')
            ->join('request.review', 'review')
            ->join('review.repository', 'repository')
            ->where('request.id = :id')
            ->setParameter('id', self::ulid($id), UlidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $request instanceof ChangeRequest ? $request : throw new NotFound();
    }

    private static function filter(QueryBuilder $builder, RequestFilter $filter): void
    {
        if ([] !== $filter->statuses) {
            $builder->andWhere('request.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (RequestStatus $status): string => $status->value, $filter->statuses), ArrayParameterType::STRING);
        }

        if ([] !== $filter->awaiting) {
            $builder->andWhere('request.awaiting IN (:awaiting)')
                ->setParameter('awaiting', array_map(static fn (Awaiting $awaiting): string => $awaiting->value, $filter->awaiting), ArrayParameterType::STRING);
        }

        if (null !== $filter->pathPrefix && '' !== $filter->pathPrefix) {
            $pattern = strtr($filter->pathPrefix, [self::LIKE_ESCAPE => self::LIKE_ESCAPE.self::LIKE_ESCAPE, '%' => self::LIKE_ESCAPE.'%', '_' => self::LIKE_ESCAPE.'_']).'%';
            $builder->andWhere(self::ANCHOR_PATH." LIKE :pathPattern ESCAPE '".self::LIKE_ESCAPE."'")->setParameter('pathPattern', $pattern);
        }

        // Null never matches.
        if (null !== $filter->outdated) {
            $builder->andWhere('request.outdated = :outdated')->setParameter('outdated', $filter->outdated, Types::BOOLEAN);
        }
    }

    /**
     * @param list<mixed>|null $after The sort key of the last item of the previous page
     */
    private static function sort(QueryBuilder $builder, RequestSort $sort, ?array $after): void
    {
        match ($sort) {
            RequestSort::Created => $builder->orderBy('request.id'),
            RequestSort::Updated => $builder->orderBy('request.updatedAt', 'DESC')->addOrderBy('request.id'),
            RequestSort::Path => $builder
                ->addSelect(self::ANCHOR_PATH.' AS HIDDEN anchorPath', self::ANCHOR_START_LINE.' AS HIDDEN anchorStartLine')
                ->orderBy('anchorPath')
                ->addOrderBy('anchorStartLine')
                ->addOrderBy('request.id'),
        };

        if (null === $after) {
            return;
        }

        match ($sort) {
            RequestSort::Created => $builder->andWhere('request.id > :afterId'),
            RequestSort::Updated => $builder
                ->andWhere('request.updatedAt < :afterUpdatedAt OR (request.updatedAt = :afterUpdatedAt AND request.id > :afterId)')
                ->setParameter('afterUpdatedAt', $after[0], Types::DATETIME_IMMUTABLE),
            RequestSort::Path => $builder
                ->andWhere(\sprintf(
                    '%1$s > :afterPath OR (%1$s = :afterPath AND (%2$s > :afterStartLine OR (%2$s = :afterStartLine AND request.id > :afterId)))',
                    self::ANCHOR_PATH,
                    self::ANCHOR_START_LINE,
                ))
                ->setParameter('afterPath', $after[0])
                ->setParameter('afterStartLine', $after[1]),
        };

        $builder->setParameter('afterId', array_last($after), UlidType::NAME);
    }

    /**
     * @return list<string|int>
     */
    private static function sortKey(ChangeRequest $request, RequestSort $sort): array
    {
        $id = $request->getId()->toString();

        return match ($sort) {
            RequestSort::Created, RequestSort::Path => [$id],
            RequestSort::Updated => [$request->getUpdatedAt()->getTimestamp(), $id],
        };
    }

    /**
     * A path cursor holds only the request ID, because a path can be longer than a cursor. The path and the start line
     * of that request never change.
     *
     * @param list<mixed> $key
     *
     * @return list<mixed> The full sort key with a Ulid last, and a time for the updated sort
     *
     * @throws InvalidInput
     */
    private function afterKey(Review $review, RequestSort $sort, array $key): array
    {
        $id = array_last($key);
        $isValid = \is_string($id) && Ulid::isValid($id) && match ($sort) {
            RequestSort::Created, RequestSort::Path => 1 === count($key),
            RequestSort::Updated => 2 === count($key) && \is_int($key[0]) && $key[0] >= self::EARLIEST_CURSOR_UNIX_SECONDS && $key[0] <= self::LATEST_CURSOR_UNIX_SECONDS,
        };

        if (!$isValid) {
            throw Cursor::malformed();
        }

        if (RequestSort::Updated === $sort) {
            return [new \DateTimeImmutable("@{$key[0]}"), Ulid::fromString($id)];
        }

        if (RequestSort::Created === $sort) {
            return [Ulid::fromString($id)];
        }

        $last = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ChangeRequest::class, 'request')
            ->where('request.id = :id')
            ->andWhere('request.review = :review')
            ->setParameter('id', Ulid::fromString($id), UlidType::NAME)
            ->setParameter('review', self::ulid($review->getId()), UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
        $anchor = $last instanceof ChangeRequest ? $last->getAnchor() : throw Cursor::malformed();

        return [$anchor->path->toString(), $anchor->range->startLine ?? 0, Ulid::fromString($id)];
    }

    /**
     * @param list<ChangeRequest> $requests
     *
     * @return array<string, list<CommitLink>> In linkedAt order, by request ID
     */
    private function linksByRequest(array $requests): array
    {
        if ([] === $requests) {
            return [];
        }

        /** @var list<CommitLink> $links */
        $links = $this->entityManager->createQueryBuilder()
            ->select('link')
            ->from(CommitLink::class, 'link')
            ->where('link.request IN (:requests)')
            ->orderBy('link.linkedAt')
            ->addOrderBy('link.commitSha')
            ->setParameter('requests', array_map(static fn (ChangeRequest $request): string => self::uuid($request->getId()), $requests), ArrayParameterType::STRING)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        $byRequest = [];

        foreach ($links as $link) {
            $byRequest[$link->getRequest()->getId()->toString()][] = $link;
        }

        return $byRequest;
    }

    /**
     * @param list<ChangeRequest> $requests
     *
     * @return array<string, int> By request ID
     */
    private function commentCounts(array $requests): array
    {
        if ([] === $requests) {
            return [];
        }

        /** @var list<array{requestId: string, comments: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(comment.request) AS requestId', 'COUNT(comment.id) AS comments')
            ->from(Comment::class, 'comment')
            ->where('comment.request IN (:requests)')
            ->groupBy('comment.request')
            ->setParameter('requests', array_map(static fn (ChangeRequest $request): string => self::uuid($request->getId()), $requests), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        $counts = [];

        foreach ($rows as $row) {
            $counts[Ulid::fromString($row['requestId'])->toBase32()] = (int) $row['comments'];
        }

        return $counts;
    }

    /**
     * @param list<CommitLink> $links The links of the request
     *
     * @return list<CommitLinkView>
     */
    private function commitViews(ChangeRequest $request, array $links): array
    {
        if ([] === $links) {
            return [];
        }

        /** @var list<array{requestId: string, commitSha: CommitSha}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(link.request) AS requestId', 'link.commitSha AS commitSha')
            ->from(CommitLink::class, 'link')
            ->join('link.request', 'other')
            ->where('other.review = :review')
            ->andWhere('link.request <> :request')
            ->andWhere('link.commitSha IN (:commitShas)')
            ->orderBy('other.id')
            ->setParameter('review', self::ulid($request->getReview()->getId()), UlidType::NAME)
            ->setParameter('request', self::ulid($request->getId()), UlidType::NAME)
            ->setParameter('commitShas', array_map(static fn (CommitLink $link): string => $link->getCommitSha()->toString(), $links), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        $others = [];

        foreach ($rows as $row) {
            $others[$row['commitSha']->toString()][] = RequestId::fromString(Ulid::fromString($row['requestId'])->toBase32());
        }

        return array_map(static fn (CommitLink $link): CommitLinkView => new CommitLinkView($link, $others[$link->getCommitSha()->toString()] ?? []), $links);
    }

    private static function ulid(Identifier $id): Ulid
    {
        return Ulid::fromString($id->toString());
    }

    /**
     * The ulid column type is a native uuid on PostgreSQL, so a list parameter holds RFC 4122 strings.
     */
    private static function uuid(Identifier $id): string
    {
        return self::ulid($id)->toRfc4122();
    }
}
