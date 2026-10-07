<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\RequestCommands;
use App\Controller\Api\Input\LinkPullRequestInput;
use App\Security\AgentUser;
use App\Security\CurrentActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Q17 of docs/specs/rest-api-requests.md section 3.
 */
final class PullRequestLinkController extends AbstractController
{
    #[Route('/api/v1/pull-request-links', name: 'api_pull_request_link_create', methods: ['POST'])]
    #[IsGranted(AgentUser::ROLE, message: CurrentActor::BEARER_ONLY)]
    public function create(
        RequestCommands $commands,
        CurrentActor $currentActor,
        #[MapRequestPayload(acceptFormat: 'json')] LinkPullRequestInput $input,
    ): JsonResponse {
        \assert(null !== $input->url);

        return $this->json($commands->linkPullRequest($input->url, $input->requestIds(), $input->note, $currentActor->get()));
    }
}
