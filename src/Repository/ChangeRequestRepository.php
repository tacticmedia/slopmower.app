<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\PullRequestUrl;
use App\Domain\Request\SuggestionState;
use App\Entity\ChangeRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChangeRequest>
 */
final class ChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeRequest::class);
    }

    /**
     * Overwrites a managed instance with the stored row, so a read under the tenant lock sees the last commit.
     */
    public function findFresh(RequestId $id): ?ChangeRequest
    {
        return $this->createQueryBuilder('request')
            ->where('request.id = :id')
            ->setParameter('id', $id, RequestId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * @return list<ChangeRequest> Overwrites managed instances with the stored rows
     */
    public function findFreshOfReview(ReviewId $reviewId): array
    {
        return $this->createQueryBuilder('request')
            ->where('request.review = :review')
            ->setParameter('review', $reviewId, ReviewId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    public function countPendingSuggestions(ReviewId $reviewId): int
    {
        return (int) $this->createQueryBuilder('request')
            ->select('COUNT(request.id)')
            ->where('request.review = :review')
            ->andWhere('request.suggestionState = :pending')
            ->setParameter('review', $reviewId, ReviewId::class)
            ->setParameter('pending', SuggestionState::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The requests of a GitHub repository that link the pull request, also under an earlier name of the repository.
     *
     * @return list<ChangeRequest>
     */
    public function findLinkingPullRequest(RepositoryId $repositoryId, int $number): array
    {
        return array_values(array_filter(
            $this->findLinkingInRepository($repositoryId),
            static fn (ChangeRequest $request): bool => array_any($request->getPullRequestUrls(), static fn (PullRequestUrl $url): bool => $number === $url->gitHubNumber()),
        ));
    }

    /**
     * @return list<int> The GitHub pull requests that the requests of the repository link, also under an earlier name of
     *                   the repository
     */
    public function findLinkedPullRequestNumbers(RepositoryId $repositoryId): array
    {
        $urls = array_merge(...array_map(static fn (ChangeRequest $request): array => $request->getPullRequestUrls(), $this->findLinkingInRepository($repositoryId)));

        return array_values(array_unique(array_filter(array_map(static fn (PullRequestUrl $url): ?int => $url->gitHubNumber(), $urls), static fn (?int $number): bool => null !== $number)));
    }

    /**
     * @return list<ChangeRequest> The requests of the repository with a pull request link
     */
    private function findLinkingInRepository(RepositoryId $repositoryId): array
    {
        /** @var list<ChangeRequest> $linking */
        $linking = $this->createQueryBuilder('request')
            ->join('request.review', 'review')
            ->where('review.repository = :repository')
            ->andWhere('request.pullRequestUrls != :none')
            ->setParameter('repository', $repositoryId, RepositoryId::class)
            ->setParameter('none', [], Types::JSONB)
            ->orderBy('request.id')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $linking;
    }
}
