<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\AnchorFactory;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Controller\Api\Input\ProposeSolutionInput;
use App\Controller\Api\Input\RequiredNoteInput;
use App\Controller\Api\Input\SuggestRequestInput;
use App\Controller\Api\Input\UnlinkPullRequestInput;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\ProposeSolution;
use App\Domain\Request\Command\Suggest;
use App\Domain\Request\Command\UnlinkCommit;
use App\Entity\ChangeRequest;
use App\Entity\Review;
use App\Security\AgentUser;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Q5, Q8, Q10, Q14, Q19, and Q20 of docs/specs/rest-api-requests.md section 3: bearer only.
 */
#[Route(name: 'api_request_')]
#[IsGranted(AgentUser::ROLE, message: CurrentActor::BEARER_ONLY)]
final class AgentRequestController extends AbstractController
{
    public function __construct(
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/api/v1/reviews/{reviewId}/suggestions', name: 'suggest', methods: ['POST'])]
    public function suggest(
        #[MapEntity(id: 'reviewId')] Review $review,
        AnchorFactory $anchors,
        #[MapRequestPayload(acceptFormat: 'json')] SuggestRequestInput $input,
    ): JsonResponse {
        \assert(null !== $input->body);
        $anchorInput = $input->anchor;
        $anchor = null;

        if (null !== $anchorInput) {
            \assert(null !== $anchorInput->kind && null !== $anchorInput->path);
            $anchor = $anchors->capture($review, $anchorInput->kind, $anchorInput->path, $anchorInput->range?->startLine, $anchorInput->range?->endLine, $anchorInput->symbol?->fqn);
        }

        $actor = $this->currentActor->get();
        $events = $this->commands->suggest($review->getId(), new Suggest($anchor, $input->body), $actor);
        $requestId = $events[0]->requestId ?? throw new \LogicException('request.opened has a requestId.');

        return $this->json($this->requests->commandResult($requestId, $actor, $events), Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_request_get', ['requestId' => $requestId])]);
    }

    #[Route('/api/v1/requests/{requestId}/claim', name: 'claim', methods: ['POST'])]
    public function claim(#[MapEntity(id: 'requestId')] ChangeRequest $request): JsonResponse
    {
        return $this->result($request, $this->commands->claim(new Claim($request->getId()), $this->currentActor->get()));
    }

    #[Route('/api/v1/requests/{requestId}/commits/{commitSha}', name: 'unlink_commit', methods: ['DELETE'])]
    public function unlinkCommit(#[MapEntity(id: 'requestId')] ChangeRequest $request, string $commitSha): JsonResponse
    {
        return $this->result($request, $this->commands->unlinkCommit(new UnlinkCommit($request->getId(), CommitSha::fromString($commitSha)), $this->currentActor->get()));
    }

    #[Route('/api/v1/requests/{requestId}/ready', name: 'ready', methods: ['POST'])]
    public function ready(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] RequiredNoteInput $input,
    ): JsonResponse {
        \assert(null !== $input->note);

        return $this->result($request, $this->commands->markReadyForReview(new MarkReadyForReview($request->getId(), $input->note), $this->currentActor->get()));
    }

    #[Route('/api/v1/requests/{requestId}/propose', name: 'propose', methods: ['POST'])]
    public function propose(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] ProposeSolutionInput $input,
    ): JsonResponse {
        \assert(null !== $input->plan);

        return $this->result($request, $this->commands->propose(new ProposeSolution($request->getId(), $input->plan, $input->diff), $this->currentActor->get()));
    }

    #[Route('/api/v1/requests/{requestId}/unlink-pull-request', name: 'unlink_pull_request', methods: ['POST'])]
    public function unlinkPullRequest(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] UnlinkPullRequestInput $input,
    ): JsonResponse {
        \assert(null !== $input->url);

        return $this->result($request, $this->commands->unlinkPullRequest($request->getId(), $input->url, $this->currentActor->get()));
    }

    /**
     * @param list<RecordedEvent> $events The events that the command wrote
     */
    private function result(ChangeRequest $request, array $events): JsonResponse
    {
        return $this->json($this->requests->commandResult($request->getId(), $this->currentActor->get(), $events));
    }
}
