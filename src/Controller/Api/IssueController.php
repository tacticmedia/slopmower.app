<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Controller\Api\Input\ApprovePlanInput;
use App\Controller\Api\Input\IssueCommentInput;
use App\Controller\Api\Input\IssueListQuery;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Issue\IssueKey;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * I1 to I4 of docs/specs/rest-api-issues.md. A path key that the SQL filters hide resolves to 404.
 */
#[Route('/api/v1', name: 'api_issue_', requirements: ['projectKey' => IssueKey::PROJECT_KEY_REQUIREMENT, 'issueKey' => IssueKey::REQUIREMENT])]
final class IssueController extends AbstractController
{
    public function __construct(
        private readonly IssueQuery $issues,
        private readonly IssueCommands $commands,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/projects/{projectKey}/issues', name: 'list', methods: ['GET'])]
    public function list(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        IssueListQuery $query = new IssueListQuery(),
    ): JsonResponse {
        return $this->json($this->issues->list($project->getKey(), $query->status, $query->limit, $query->cursor));
    }

    #[Route('/issues/{issueKey}', name: 'get', methods: ['GET'])]
    public function get(#[ValueResolver(IssueKeyResolver::class)] Issue $issue): JsonResponse
    {
        return $this->json($this->issues->get($issue->getKey(), $this->currentActor->get()));
    }

    #[Route('/issues/{issueKey}/comments', name: 'comment', methods: ['POST'])]
    public function comment(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] IssueCommentInput $input,
    ): JsonResponse {
        \assert(null !== $input->body);
        $actor = $this->currentActor->get();

        return $this->json($this->issues->view($this->commands->comment($issue->getKey(), $input->body, $actor), $actor), Response::HTTP_CREATED);
    }

    /**
     * The approval of a user, with no reviewer and no review note.
     */
    #[Route('/issues/{issueKey}/plan-approval', name: 'approve_plan', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function approvePlan(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] ApprovePlanInput $input,
    ): JsonResponse {
        \assert(null !== $input->planRevision);
        $actor = $this->currentActor->get();
        $approved = $this->commands->approvePlan($issue->getKey(), IssueEntryId::fromString($input->planRevision, 'planRevision'), null, null, $actor);

        return $this->json($this->issues->view($approved, $actor));
    }
}
