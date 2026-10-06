<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Controller\Api\Input\RequiredNoteInput;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\UnlinkCommit;
use App\Entity\ChangeRequest;
use App\Security\AgentUser;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Q5, Q8, and Q10 of docs/specs/rest-api-requests.md section 3: bearer only.
 */
#[Route('/api/v1/requests/{requestId}', name: 'api_request_')]
#[IsGranted(AgentUser::ROLE, message: CurrentActor::BEARER_ONLY)]
final class AgentRequestController extends AbstractController
{
    public function __construct(
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/claim', name: 'claim', methods: ['POST'])]
    public function claim(#[MapEntity(id: 'requestId')] ChangeRequest $request): JsonResponse
    {
        return $this->result($request, $this->commands->claim(new Claim($request->getId()), $this->currentActor->get()));
    }

    #[Route('/commits/{commitSha}', name: 'unlink_commit', methods: ['DELETE'])]
    public function unlinkCommit(#[MapEntity(id: 'requestId')] ChangeRequest $request, string $commitSha): JsonResponse
    {
        return $this->result($request, $this->commands->unlinkCommit(new UnlinkCommit($request->getId(), CommitSha::fromString($commitSha)), $this->currentActor->get()));
    }

    #[Route('/ready', name: 'ready', methods: ['POST'])]
    public function ready(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] RequiredNoteInput $input,
    ): JsonResponse {
        \assert(null !== $input->note);

        return $this->result($request, $this->commands->markReadyForReview(new MarkReadyForReview($request->getId(), $input->note), $this->currentActor->get()));
    }

    /**
     * @param list<RecordedEvent> $events The events that the command wrote
     */
    private function result(ChangeRequest $request, array $events): JsonResponse
    {
        return $this->json($this->requests->commandResult($request->getId(), $this->currentActor->get(), $events));
    }
}
