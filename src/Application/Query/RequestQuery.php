<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Anchoring\Locator;
use App\Application\FetchRepository;
use App\Domain\Actor\Actor;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\LocationTarget;
use App\Domain\Repository\RepositorySource;
use App\Domain\Request\AllowedActions;
use App\Domain\Request\Awaiting;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\PullRequestState;
use App\Domain\Request\PullRequestUrl;
use App\Domain\Request\RequestStatus;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Repository;
use App\Entity\Review;
use App\Git\GitReader;
use App\Http\Cursor;
use App\Projection\RequestStateMapper;
use App\Repository\PullRequestRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Q1, Q3, and the results of the request commands of docs/specs/rest-api-requests.md section 3. Reads refresh the
 * rows, so a read after a command sees it.
 */
final class RequestQuery
{
    /** One expression for the filter, the order, and the cursor. A general request gets `''`, which is never a valid path. */
    private const string ANCHOR_PATH = "COALESCE(JSONB_PATH_TEXT(request.anchor, 'path'), '')";

    /** File anchors and general requests have no range, so they sort first. */
    private const string ANCHOR_START_LINE = "COALESCE(JSONB_PATH_INTEGER(request.anchor, 'range', 'startLine'), 0)";

    private const string LIKE_ESCAPE = '!';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ThreadQuery $threads,
        private readonly Locator $locator,
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly PullRequestRepository $pullRequests,
    ) {
    }

    /**
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public function list(Review $review, RequestFilter $filter, int $limit, ?string $cursor): RequestPage
    {
        $cursorFilters = $filter->cursorFilters();
        $after = null === $cursor ? null : $this->afterKey($review, $filter->sort, $cursor, $cursorFilters);
        $builder = $this->entityManager->createQueryBuilder()
            ->from(ChangeRequest::class, 'request')
            ->where('request.review = :review')
            ->setParameter('review', $review->getId(), ReviewId::class);
        self::filter($builder, $filter);
        $total = (int) (clone $builder)->select('COUNT(request.id)')->getQuery()->getSingleScalarResult();
        $builder->select('request');
        self::sort($builder, $filter->sort, $after);

        /** @var list<ChangeRequest> $rows */
        $rows = $builder->setMaxResults($limit + 1)->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $nextCursor = Cursor::next($rows, $limit, $cursorFilters, static fn (ChangeRequest $request): array => self::sortKey($request, $filter->sort));

        return new RequestPage($review->getId(), $review->getState(), $total, $this->summaries(array_slice($rows, 0, $limit)), $nextCursor);
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
        $anchor = $request->getAnchor();
        // A general request is never located, so `at` is not resolved.
        $target = null === $anchor ? null : $this->target($request, $at);

        // The fetch for `at` can record commit.found.
        if (null !== $target && null !== $at) {
            $request = $this->fresh($id);
        }

        $links = $this->linksByRequest([$request])[$id->toString()] ?? [];
        $summary = $this->summary($request, $links, $this->commentCounts([$request])[$id->toRfc4122()] ?? 0);
        $thread = $this->threads->entries($request->getReview()->getId(), $id);
        // request.opened, whose ID is the cache key of the location.
        $opened = $thread[0]->event ?? null;
        $repository = $request->getReview()->getRepository();
        $location = null;
        $excerpt = null;

        if (null !== $anchor && null !== $target && $opened?->event instanceof RequestOpenedEvent) {
            $located = $this->locator->locate($repository, $opened->id, $anchor, $target[1]);
            $location = LocationView::of($target[0], $located);
            $excerpt = null === $contextLines ? null : $this->locator->excerpt($repository, $located, $contextLines);
        }

        return new RequestView(
            $summary,
            $thread,
            $this->commitViews($request, $links),
            $this->pullRequestViews($repository, $summary->state->pullRequestUrls),
            AllowedActions::for($summary->state, $request->getReview()->toSnapshot(), $caller),
            $location,
            $excerpt,
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
     * @param list<RequestId>     $requestIds The requests that the command named
     * @param list<RecordedEvent> $events     The pull_request.linked event that the command wrote, if any
     *
     * @throws NotFound
     */
    public function linkPullRequestResult(PullRequestUrl $url, array $requestIds, array $events): LinkPullRequestResult
    {
        return new LinkPullRequestResult(
            $url,
            $this->summaries(array_map($this->fresh(...), $requestIds)),
            array_map(static fn (RecordedEvent $event): EventView => new EventView($event, null), $events),
        );
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
            $commentCounts[$request->getId()->toRfc4122()] ?? 0,
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
            $gitDir = $this->fetchRepository->readyClonePath($repositoryId);

            try {
                return [LocationTarget::Requested, $this->reader->resolvePrefix($gitDir, $at, 'at')];
            } catch (CommitNotFound) {
                // F14: a commit missing from the server clone gets one synchronous fetch.
                $this->fetchRepository->fetchNow($repositoryId);

                return [LocationTarget::Requested, $this->reader->resolvePrefix($gitDir, $at, 'at')];
            }
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
            ->setParameter('id', $id, RequestId::class)
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
            RequestSort::Updated => $builder->orderBy('request.updatedAt', \SortDirection::Descending)->addOrderBy('request.id'),
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
     * @param array<string, string|bool|null> $filters
     *
     * @return list<mixed> The full sort key with a Ulid last, and a time for the updated sort
     *
     * @throws InvalidInput
     */
    private function afterKey(Review $review, RequestSort $sort, string $cursor, array $filters): array
    {
        if (RequestSort::Updated === $sort) {
            return Cursor::decode($cursor, $filters, 'time', 'ulid');
        }

        $key = Cursor::decode($cursor, $filters, 'ulid');

        if (RequestSort::Created === $sort) {
            return $key;
        }

        $last = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ChangeRequest::class, 'request')
            ->where('request.id = :id')
            ->andWhere('request.review = :review')
            ->setParameter('id', $key[0], UlidType::NAME)
            ->setParameter('review', $review->getId(), ReviewId::class)
            ->getQuery()
            ->getOneOrNullResult();
        $anchor = $last instanceof ChangeRequest ? $last->getAnchor() : throw Cursor::malformed();

        return [$anchor?->path->toString() ?? '', $anchor?->range->startLine ?? 0, $key[0]];
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
            ->setParameter('requests', array_map(static fn (ChangeRequest $request): string => $request->getId()->toRfc4122(), $requests), ArrayParameterType::STRING)
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
     * @return array<string, int> By request ID in RFC 4122 form
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
            ->setParameter('requests', array_map(static fn (ChangeRequest $request): string => $request->getId()->toRfc4122(), $requests), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['requestId']] = (int) $row['comments'];
        }

        return $counts;
    }

    /**
     * The state of a GitHub pull request comes from its record, found by number, so a link under an earlier name of the
     * repository shows it too.
     *
     * @param list<PullRequestUrl> $urls
     *
     * @return list<PullRequestView>
     */
    private function pullRequestViews(Repository $repository, array $urls): array
    {
        $records = RepositorySource::Github === $repository->getSource()
            ? $this->pullRequests->findOfRepository($repository->getId(), array_values(array_filter(array_map(static fn (PullRequestUrl $url): ?int => $url->gitHubNumber(), $urls))))
            : [];

        return array_map(static function (PullRequestUrl $url) use ($records): PullRequestView {
            $record = $records[$url->gitHubNumber() ?? 0] ?? null;

            return new PullRequestView($url, $record?->shownState() ?? PullRequestState::Unknown, $record?->getTitle());
        }, $urls);
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

        /** @var list<array{requestId: RequestId, commitSha: CommitSha}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('other.id AS requestId', 'link.commitSha AS commitSha')
            ->from(CommitLink::class, 'link')
            ->join('link.request', 'other')
            ->where('other.review = :review')
            ->andWhere('link.request <> :request')
            ->andWhere('link.commitSha IN (:commitShas)')
            ->orderBy('other.id')
            ->setParameter('review', $request->getReview()->getId(), ReviewId::class)
            ->setParameter('request', $request->getId(), RequestId::class)
            ->setParameter('commitShas', array_map(static fn (CommitLink $link): string => $link->getCommitSha()->toString(), $links), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        $others = [];

        foreach ($rows as $row) {
            $others[$row['commitSha']->toString()][] = $row['requestId'];
        }

        return array_map(static fn (CommitLink $link): CommitLinkView => new CommitLinkView($link, $others[$link->getCommitSha()->toString()] ?? []), $links);
    }
}
