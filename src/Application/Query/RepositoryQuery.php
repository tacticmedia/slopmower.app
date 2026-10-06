<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositorySource;
use App\Domain\Review\ReviewState;
use App\Entity\Repository;
use App\Entity\Review;
use App\Http\Cursor;
use App\Repository\RepositoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Reads refresh the rows: the sync columns change through the conditional updates of {@see RepositoryRepository}.
 */
final class RepositoryQuery
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws NotFound
     */
    public function get(RepositoryId $id): RepositoryView
    {
        $row = $this->select()
            ->where('repository.id = :id')
            ->setParameter('id', $id, RepositoryId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return null === $row ? throw new NotFound() : self::view($row);
    }

    /**
     * R1: sorted by name case-insensitively, then by ID. A name resolves as docs/specs/README.md section 7 says.
     *
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public function list(?string $name, ?RepositorySource $source, int $limit, ?string $cursor): RepositoryPage
    {
        $filters = ['name' => $name, 'source' => $source?->value];
        $builder = $this->select()
            ->orderBy('sortName')
            ->addOrderBy('repository.id')
            ->setMaxResults($limit + 1);

        if (null !== $name) {
            self::whereName($builder, $name);
        }

        if (null !== $source) {
            $builder->andWhere('repository.source = :source')->setParameter('source', $source);
        }

        if (null !== $cursor) {
            [$afterName, $afterId] = Cursor::decode($cursor, $filters, 'string', 'ulid');
            $builder
                ->andWhere('LOWER(repository.name) > :afterName OR (LOWER(repository.name) = :afterName AND repository.id > :afterId)')
                ->setParameter('afterName', $afterName)
                ->setParameter('afterId', $afterId, UlidType::NAME);
        }

        /** @var list<array{0: Repository, sortName: string, openReviewCount: int}> $rows */
        $rows = $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $nextCursor = Cursor::next($rows, $limit, $filters, static fn (array $row): array => [$row['sortName'], $row[0]->getId()->toString()]);

        return new RepositoryPage(array_map(self::view(...), array_slice($rows, 0, $limit)), $nextCursor);
    }

    private function select(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('repository', 'LOWER(repository.name) AS sortName')
            ->addSelect(sprintf('(SELECT COUNT(review.id) FROM %s review WHERE review.repository = repository AND review.state = :openState) AS openReviewCount', Review::class))
            ->from(Repository::class, 'repository')
            ->setParameter('openState', ReviewState::Open);
    }

    private static function whereName(QueryBuilder $builder, string $name): void
    {
        match (true) {
            Ulid::isValid($name) => $builder
                ->andWhere('repository.id = :nameId')
                ->setParameter('nameId', $name, RepositoryId::class),
            str_contains($name, '/') => $builder
                ->andWhere('repository.source = :nameSource AND LOWER(repository.name) = LOWER(:name)')
                ->setParameter('nameSource', RepositorySource::Github)
                ->setParameter('name', $name),
            default => $builder
                ->andWhere('repository.source = :nameSource AND repository.name = :name')
                ->setParameter('nameSource', RepositorySource::Local)
                ->setParameter('name', $name),
        };
    }

    /**
     * @param array{0: Repository, openReviewCount: int|string} $row
     */
    private static function view(array $row): RepositoryView
    {
        return new RepositoryView($row[0], (int) $row['openReviewCount']);
    }
}
