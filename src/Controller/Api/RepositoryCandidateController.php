<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\RepositoryQuery;
use App\Controller\Api\Input\CandidateListQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final class RepositoryCandidateController extends AbstractController
{
    /**
     * R2. The priority puts the route before /api/v1/repositories/{repositoryId}.
     */
    #[Route('/api/v1/repositories/candidates', name: 'api_repository_candidates', methods: ['GET'], priority: 1)]
    public function __invoke(
        RepositoryQuery $repositories,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        CandidateListQuery $query = new CandidateListQuery(),
    ): JsonResponse {
        return $this->json($repositories->candidates($query->source, $query->q, $query->includeConnected));
    }
}
