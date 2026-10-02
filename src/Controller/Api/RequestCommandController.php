<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Controller\Api\Input\CommentInput;
use App\Controller\Api\Input\OptionalNoteInput;
use App\Controller\Api\Input\RequiredNoteInput;
use App\Controller\Api\Input\WontDoInput;
use App\Domain\Event\RecordedEvent;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\Verify;
use App\Entity\ChangeRequest;
use App\Entity\User;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Q4, Q6, and Q11 to Q13 of docs/specs/rest-api-requests.md section 3.
 */
#[Route('/api/v1/requests/{requestId}', name: 'api_request_')]
final class RequestCommandController extends AbstractController
{
    public function __construct(
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/comments', name: 'comment', methods: ['POST'])]
    public function comment(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] CommentInput $input,
    ): JsonResponse {
        \assert(null !== $input->body);

        return $this->result($request, $this->commands->reply(new Reply($request->getId(), $input->body, $input->question ?? false), $this->currentActor->get()), Response::HTTP_CREATED);
    }

    /**
     * "Unassign" for a user.
     */
    #[Route('/release', name: 'release', methods: ['POST'])]
    public function release(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] OptionalNoteInput $input = new OptionalNoteInput(),
    ): JsonResponse {
        return $this->result($request, $this->commands->release(new Release($request->getId(), $input->note), $this->currentActor->get()));
    }

    #[Route('/wont-do', name: 'wont_do', methods: ['POST'])]
    public function wontDo(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] WontDoInput $input,
    ): JsonResponse {
        \assert(null !== $input->reason);

        return $this->result($request, $this->commands->markWontDo(new MarkWontDo($request->getId(), $input->reason), $this->currentActor->get()));
    }

    #[Route('/verify', name: 'verify', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function verify(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] OptionalNoteInput $input = new OptionalNoteInput(),
    ): JsonResponse {
        return $this->result($request, $this->commands->verify(new Verify($request->getId(), $input->note), $this->currentActor->get()));
    }

    #[Route('/reopen', name: 'reopen', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function reopen(
        #[MapEntity(id: 'requestId')] ChangeRequest $request,
        #[MapRequestPayload(acceptFormat: 'json')] RequiredNoteInput $input,
    ): JsonResponse {
        \assert(null !== $input->note);

        return $this->result($request, $this->commands->reopen(new Reopen($request->getId(), $input->note), $this->currentActor->get()));
    }

    /**
     * @param list<RecordedEvent> $events The events that the command wrote
     */
    private function result(ChangeRequest $request, array $events, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json($this->requests->commandResult($request->getId(), $this->currentActor->get(), $events), $status);
    }
}
