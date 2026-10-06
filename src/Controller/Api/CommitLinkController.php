<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\RequestCommands;
use App\Controller\Api\Input\LinkCommitsInput;
use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\LinkCommits;
use App\Security\AgentUser;
use App\Security\CurrentActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Q7 of docs/specs/rest-api-requests.md section 3.
 */
final class CommitLinkController extends AbstractController
{
    #[Route('/api/v1/commit-links', name: 'api_commit_link_create', methods: ['POST'])]
    #[IsGranted(AgentUser::ROLE, message: CurrentActor::BEARER_ONLY)]
    public function create(
        RequestCommands $commands,
        CurrentActor $currentActor,
        #[MapRequestPayload(acceptFormat: 'json')] LinkCommitsInput $input,
    ): JsonResponse {
        \assert(null !== $input->commitSha);

        return $this->json($commands->linkCommits(new LinkCommits(CommitSha::fromString($input->commitSha), $input->requestIds(), $input->note), $currentActor->get()));
    }
}
