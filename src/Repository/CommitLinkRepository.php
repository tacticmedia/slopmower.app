<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\Type\CommitShaType;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\CommitLinkState;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<CommitLink>
 */
final class CommitLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommitLink::class);
    }

    /**
     * A bulk delete: it bypasses the identity map.
     */
    public function deleteOfTenant(TenantId $tenantId): void
    {
        $this->createQueryBuilder('link')
            ->delete()
            ->where('link.tenantId = :tenantId')
            ->setParameter('tenantId', Ulid::fromString($tenantId->toString()), UlidType::NAME)
            ->getQuery()
            ->execute();
    }

    /**
     * Overwrites managed instances with the stored rows, so a read under the tenant lock sees the last commit.
     *
     * @return list<CommitLink>
     */
    public function findFreshOf(ChangeRequest $request): array
    {
        return $this->createQueryBuilder('link')
            ->where('link.request = :request')
            ->setParameter('request', Ulid::fromString($request->getId()->toString()), UlidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * With their requests and reviews, refreshed like {@see findFreshOf()}.
     *
     * @return list<CommitLink>
     */
    public function findFreshPendingOfRepository(RepositoryId $repositoryId): array
    {
        return $this->ofRepository($repositoryId)
            ->addSelect('request', 'review')
            ->andWhere('link.state = :pending')
            ->setParameter('pending', CommitLinkState::Pending)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * @return list<CommitSha>
     */
    public function commitShasOfReview(ReviewId $reviewId): array
    {
        $shas = $this->createQueryBuilder('link')
            ->select('DISTINCT link.commitSha')
            ->join('link.request', 'request')
            ->where('request.review = :review')
            ->setParameter('review', Ulid::fromString($reviewId->toString()), UlidType::NAME)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (string $sha): CommitSha => CommitSha::fromString($sha), $shas));
    }

    /**
     * Whether a link of any request of the repository names the commit, so its pin is in use.
     */
    public function isLinkedInRepository(RepositoryId $repositoryId, CommitSha $commitSha): bool
    {
        return 0 < (int) $this->ofRepository($repositoryId)
            ->select('COUNT(link.commitSha)')
            ->andWhere('link.commitSha = :commitSha')
            ->setParameter('commitSha', $commitSha, CommitShaType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function ofRepository(RepositoryId $repositoryId): QueryBuilder
    {
        return $this->createQueryBuilder('link')
            ->join('link.request', 'request')
            ->join('request.review', 'review')
            ->where('review.repository = :repository')
            ->setParameter('repository', Ulid::fromString($repositoryId->toString()), UlidType::NAME);
    }
}
