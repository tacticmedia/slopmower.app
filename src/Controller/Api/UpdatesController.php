<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\UpdatesQuery;
use App\Controller\Api\Input\UpdatesCursorQuery;
use App\Entity\Review;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V10, docs/specs/rest-api-requests.md section 4.
 */
final class UpdatesController extends AbstractController
{
    #[Route('/api/v1/reviews/{reviewId}/updates', name: 'api_review_updates', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'reviewId')] Review $review,
        UpdatesQuery $updates,
        CurrentActor $currentActor,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        UpdatesCursorQuery $query = new UpdatesCursorQuery(),
    ): JsonResponse {
        return $this->json($updates->get($review->getId(), $currentActor->get(), $query->cursor, $query->limit, $query->includeOwn));
    }
}
