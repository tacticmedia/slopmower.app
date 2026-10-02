<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\ReviewQuery;
use App\Application\ReviewCommands;
use App\Controller\Api\Input\CloseReviewInput;
use App\Controller\Api\Input\ReviewListQuery;
use App\Controller\Api\Input\StartReviewInput;
use App\Domain\Git\RefName;
use App\Domain\Review\Command\CloseReview;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\User;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * V1 to V4 and V13 of docs/specs/rest-api.md section 5.
 */
#[Route(name: 'api_review_')]
final class ReviewController extends AbstractController
{
    public function __construct(
        private readonly ReviewQuery $reviews,
        private readonly ReviewCommands $reviewCommands,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/api/v1/repositories/{repositoryId}/reviews', name: 'start', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function start(
        #[MapEntity(id: 'repositoryId')] Repository $repository,
        #[MapRequestPayload(acceptFormat: 'json')] StartReviewInput $input = new StartReviewInput(),
    ): JsonResponse {
        $ref = null === $input->ref ? null : RefName::fromString($input->ref);
        $reviewId = $this->reviewCommands->start($repository->getId(), $ref, $input->title, $this->currentActor->get());

        return $this->json($this->reviews->get($reviewId), Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_review_get', ['reviewId' => $reviewId])]);
    }

    #[Route('/api/v1/repositories/{repositoryId}/reviews', name: 'list', methods: ['GET'])]
    public function list(
        #[MapEntity(id: 'repositoryId')] Repository $repository,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ReviewListQuery $query = new ReviewListQuery(),
    ): JsonResponse {
        return $this->json($this->reviews->list($repository->getId(), $query->state, $query->limit, $query->cursor));
    }

    #[Route('/api/v1/reviews/{reviewId}', name: 'get', methods: ['GET'])]
    public function get(#[MapEntity(id: 'reviewId')] Review $review): JsonResponse
    {
        return $this->json($this->reviews->get($review->getId()));
    }

    #[Route('/api/v1/reviews/{reviewId}/close', name: 'close', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function close(
        #[MapEntity(id: 'reviewId')] Review $review,
        #[MapRequestPayload(acceptFormat: 'json')] CloseReviewInput $input = new CloseReviewInput(),
    ): JsonResponse {
        $this->reviewCommands->close($review->getId(), new CloseReview($input->note), $this->currentActor->get());

        return $this->json($this->reviews->get($review->getId()));
    }

    #[Route('/api/v1/reviews/{reviewId}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function delete(#[MapEntity(id: 'reviewId')] Review $review): Response
    {
        $this->reviewCommands->delete($review->getId());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
