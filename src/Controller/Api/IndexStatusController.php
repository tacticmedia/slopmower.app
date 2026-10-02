<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\IndexStatus;
use App\Entity\Review;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V5 of docs/specs/rest-api.md section 5.
 */
final class IndexStatusController extends AbstractController
{
    #[Route('/api/v1/reviews/{reviewId}/index', name: 'api_review_index', methods: ['GET'])]
    public function __invoke(#[MapEntity(id: 'reviewId')] Review $review): JsonResponse
    {
        return $this->json(IndexStatus::of($review));
    }
}
