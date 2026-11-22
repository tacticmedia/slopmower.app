<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\IssueQuery;
use App\Controller\Api\Input\ProjectListQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P1 of docs/specs/rest-api-issues.md.
 */
final class ProjectController extends AbstractController
{
    #[Route('/api/v1/projects', name: 'api_project_list', methods: ['GET'])]
    public function list(
        IssueQuery $issues,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProjectListQuery $query = new ProjectListQuery(),
    ): JsonResponse {
        return $this->json($issues->projects($query->limit, $query->cursor));
    }
}
