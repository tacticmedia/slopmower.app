<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Entity\ChangeRequest;
use App\Entity\Review;
use App\Http\Cursor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * V2 and V3, with the request counts of each review. Reads refresh the rows, so a read after a command sees it.
 */
final class ReviewQuery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryQuery $repositories,
    ) {
    }

    /**
     * @throws NotFound
     */
    public function get(ReviewId $id): ReviewView
    {
        $review = $this->select()
            ->where('review.id = :id')
            ->setParameter('id', Ulid::fromString($id->toString()), UlidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $review instanceof Review ? $this->views([$review])[0] : throw new NotFound();
    }

    /**
     * Sorted by createdAt descending, then by ID descending.
     *
     * @throws NotFound
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public function list(RepositoryId $repositoryId, ReviewStateFilter $state, int $limit, ?string $cursor): ReviewPage
    {
        $repository = $this->repositories->get($repositoryId);
        $filters = ['state' => $state->value];
        $builder = $this->select()
            ->where('review.repository = :repository')
            ->setParameter('repository', Ulid::fromString($repositoryId->toString()), UlidType::NAME)
            ->orderBy('review.createdAt', 'DESC')
            ->addOrderBy('review.id', 'DESC')
            ->setMaxResults($limit + 1);

        if (null !== $state->state()) {
            $builder->andWhere('review.state = :state')->setParameter('state', $state->state());
        }

        if (null !== $cursor) {
            [$beforeCreatedAt, $beforeId] = Cursor::decode($cursor, $filters, 'time', 'ulid');
            $builder
                ->andWhere('review.createdAt < :beforeCreatedAt OR (review.createdAt = :beforeCreatedAt AND review.id < :beforeId)')
                ->setParameter('beforeCreatedAt', $beforeCreatedAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('beforeId', $beforeId, UlidType::NAME);
        }

        /** @var list<Review> $rows */
        $rows = $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $nextCursor = Cursor::next($rows, $limit, $filters, static fn (Review $review): array => [$review->getCreatedAt()->getTimestamp(), $review->getId()->toString()]);

        return new ReviewPage($repository, $this->views(array_slice($rows, 0, $limit)), $nextCursor);
    }

    private function select(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('review', 'repository')
            ->from(Review::class, 'review')
            ->join('review.repository', 'repository');
    }

    /**
     * Counts the requests of all reviews in one query.
     *
     * @param list<Review> $reviews
     *
     * @return list<ReviewView>
     */
    private function views(array $reviews): array
    {
        $byStatus = array_fill_keys(array_column(RequestStatus::cases(), 'value'), 0);
        $byAwaiting = array_fill_keys(array_column(Awaiting::cases(), 'value'), 0);
        $counts = [];
        /** @var list<array{reviewId: string, status: RequestStatus, awaiting: Awaiting, requests: int|string, outdated: int|string}> $rows */
        $rows = [] === $reviews ? [] : $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(request.review) AS reviewId',
                'request.status AS status',
                'request.awaiting AS awaiting',
                'COUNT(request.id) AS requests',
                'SUM(CASE WHEN request.outdated = TRUE THEN 1 ELSE 0 END) AS outdated',
            )
            ->from(ChangeRequest::class, 'request')
            ->where('request.review IN (:reviews)')
            ->groupBy('request.review', 'request.status', 'request.awaiting')
            ->setParameter('reviews', array_map(static fn (Review $review): string => $review->getId()->toRfc4122(), $reviews), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $reviewId = Ulid::fromString($row['reviewId'])->toBase32();
            $counts[$reviewId]['status'][$row['status']->value] = ($counts[$reviewId]['status'][$row['status']->value] ?? 0) + (int) $row['requests'];
            $counts[$reviewId]['awaiting'][$row['awaiting']->value] = ($counts[$reviewId]['awaiting'][$row['awaiting']->value] ?? 0) + (int) $row['requests'];
            $counts[$reviewId]['outdated'] = ($counts[$reviewId]['outdated'] ?? 0) + (int) $row['outdated'];
        }

        return array_map(static fn (Review $review): ReviewView => new ReviewView(
            $review,
            array_replace($byStatus, $counts[$review->getId()->toString()]['status'] ?? []),
            array_replace($byAwaiting, $counts[$review->getId()->toString()]['awaiting'] ?? []),
            $counts[$review->getId()->toString()]['outdated'] ?? 0,
        ), $reviews);
    }
}
