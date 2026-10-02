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
use App\Source\LocalRepositoryDiscovery;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositories,
        private readonly LocalRepositoryDiscovery $discovery,
    ) {
    }

    /**
     * @throws NotFound
     */
    public function get(RepositoryId $id): RepositoryView
    {
        $row = $this->select()
            ->where('repository.id = :id')
            ->setParameter('id', Ulid::fromString($id->toString()), UlidType::NAME)
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
            [$afterName, $afterId] = self::sortKey(Cursor::decode($cursor, $filters));
            $builder
                ->andWhere('LOWER(repository.name) > :afterName OR (LOWER(repository.name) = :afterName AND repository.id > :afterId)')
                ->setParameter('afterName', $afterName)
                ->setParameter('afterId', $afterId, UlidType::NAME);
        }

        /** @var list<array{0: Repository, sortName: string, openReviewCount: int}> $rows */
        $rows = $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $page = array_slice($rows, 0, $limit);
        $last = end($page);
        $nextCursor = count($rows) > $limit && false !== $last
            ? Cursor::encode([$last['sortName'], $last[0]->getId()->toString()], $filters)
            : null;

        return new RepositoryPage(array_map(self::view(...), $page), $nextCursor);
    }

    /**
     * R2. Local candidates come from a new scan on every call. The filters apply before the 1,000-item limit.
     */
    public function candidates(?RepositorySource $source, ?string $q, bool $includeConnected): CandidateList
    {
        $localEnabled = $this->discovery->isEnabled();

        if (RepositorySource::Github === $source) {
            return new CandidateList([], [], false, $localEnabled);
        }

        $connectedIds = [];

        foreach ($this->repositories->findBy(['source' => RepositorySource::Local]) as $repository) {
            $connectedIds[(string) $repository->getLocalDirectory()] = $repository->getId();
        }

        $listing = $this->discovery->list(static fn (string $directory): bool => (null === $q || false !== mb_stripos($directory, $q))
            && ($includeConnected || !isset($connectedIds[$directory])));

        return new CandidateList($listing->candidates, $connectedIds, $listing->truncated, $localEnabled);
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
                ->setParameter('nameId', Ulid::fromString($name), UlidType::NAME),
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
     * @param list<mixed> $sortKey
     *
     * @return array{string, Ulid}
     *
     * @throws InvalidInput
     */
    private static function sortKey(array $sortKey): array
    {
        if (2 !== count($sortKey) || !\is_string($sortKey[0]) || !\is_string($sortKey[1]) || !Ulid::isValid($sortKey[1])) {
            throw Cursor::malformed();
        }

        return [$sortKey[0], Ulid::fromString($sortKey[1])];
    }

    /**
     * @param array{0: Repository, openReviewCount: int|string} $row
     */
    private static function view(array $row): RepositoryView
    {
        return new RepositoryView($row[0], (int) $row['openReviewCount']);
    }
}
