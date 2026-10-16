<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\AnchorFactory;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Controller\Api\Input\OpenRequestInput;
use App\Controller\Api\Input\RequestListQuery;
use App\Controller\Api\Input\RequestLocationQuery;
use App\Domain\Request\Command\Open;
use App\Entity\ChangeRequest;
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
 * Q1 to Q3 of docs/specs/rest-api-requests.md.
 */
#[Route(name: 'api_request_')]
final class RequestController extends AbstractController
{
    public function __construct(
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/api/v1/reviews/{reviewId}/requests', name: 'list', methods: ['GET'])]
    public function list(
        #[MapEntity(id: 'reviewId')] Review $review,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        RequestListQuery $query = new RequestListQuery(),
    ): JsonResponse {
        return $this->json($this->requests->list($review, $query->filter(), $query->limit, $query->cursor));
    }

    #[Route('/api/v1/reviews/{reviewId}/requests', name: 'open', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function open(
        #[MapEntity(id: 'reviewId')] Review $review,
        AnchorFactory $anchors,
        RequestCommands $commands,
        #[MapRequestPayload(acceptFormat: 'json')] OpenRequestInput $input,
    ): JsonResponse {
        \assert(null !== $input->body);
        $anchorInput = $input->anchor;
        $anchor = null;

        if (null !== $anchorInput) {
            \assert(null !== $anchorInput->kind && null !== $anchorInput->path);
            $anchor = $anchors->capture($review, $anchorInput->kind, $anchorInput->path, $anchorInput->range?->startLine, $anchorInput->range?->endLine, $anchorInput->symbol?->fqn);
        }

        $actor = $this->currentActor->get();
        [$opened] = $commands->open($review->getId(), new Open($anchor, $input->body, requireApproval: $input->requireApproval ?? false, title: $input->title), $actor);
        $requestId = $opened->requestId ?? throw new \LogicException('request.opened has a requestId.');

        return $this->json($this->requests->get($requestId, $actor), Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_request_get', ['requestId' => $requestId])]);
    }

    #[Route('/api/v1/requests/{requestId}', name: 'get', methods: ['GET'])]
    public function get(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        RequestLocationQuery $query = new RequestLocationQuery(),
    ): JsonResponse {
        return $this->json($this->requests->get($request->getId(), $this->currentActor->get(), $query->at(), $query->context));
    }
}
