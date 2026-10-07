<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\SearchHitList;
use App\Controller\Api\Input\SearchQuery;
use App\Domain\Review\IndexState;
use App\Entity\Review;
use App\Index\IndexSql;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V16 of docs/specs/rest-api.md section 5. It reads only the index, so it needs no clone.
 */
final class SearchController extends AbstractController
{
    #[Route('/api/v1/reviews/{reviewId}/search', name: 'api_review_search', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'reviewId')] Review $review,
        IndexSql $indexSql,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        SearchQuery $query,
    ): JsonResponse {
        $repository = $review->getRepository();
        $hits = $indexSql->search($repository->getTenantId(), $repository->getId(), $review->getCommitSha(), (string) $query->q, $query->limit);

        return $this->json(new SearchHitList($review->getId(), IndexState::Ready !== $review->getIndexState(), $hits));
    }
}
