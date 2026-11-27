<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\IssueQuery;
use App\Controller\Api\Input\CreateProjectInput;
use App\Controller\Api\Input\ProjectListQuery;
use App\Controller\Api\Input\UpdateProjectInput;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\RepositoryId;
use App\Domain\Issue\IssueKey;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\RepositoryRepository;
use App\Security\CurrentActor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * P1 to P3 of docs/specs/rest-api-issues.md. Only a session creates and changes a project, so no agent sets the
 * approval mode that binds it.
 */
#[Route('/api/v1/projects', name: 'api_project_')]
final class ProjectController extends AbstractController
{
    public function __construct(private readonly IssueQuery $issues, private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProjectListQuery $query = new ProjectListQuery(),
    ): JsonResponse {
        return $this->json($this->issues->projects($query->limit, $query->cursor));
    }

    /**
     * Of two concurrent creates with one key, the unique index lets one insert.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')] CreateProjectInput $input,
        CurrentActor $currentActor,
        ManagerRegistry $doctrine,
        ClockInterface $clock,
    ): JsonResponse {
        \assert(null !== $input->key && null !== $input->name && null !== $input->approvalMode);
        $taken = InvalidInput::field('key', "The project {$input->key} exists already.");

        if (null !== $this->entityManager->getRepository(Project::class)->findOneBy(['key' => $input->key])) {
            throw $taken;
        }

        $project = new Project($currentActor->sessionUser()->getTenantId(), $input->key, trim($input->name), $input->approvalMode, $clock->now());
        $project->setApprovalGuidelines($input->approvalGuidelines);
        $this->entityManager->persist($project);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The failed flush closed the entity manager.
            $doctrine->resetManager();

            throw $taken;
        }

        return $this->json($this->issues->projectView($project), Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_project_update', ['projectKey' => $project->getKey()])]);
    }

    /**
     * Every check runs before the first change: the 404 of each ID, then the 422 of a repository of another project
     * (docs/specs/README.md section 4).
     */
    #[Route('/{projectKey}', name: 'update', requirements: ['projectKey' => IssueKey::PROJECT_KEY_REQUIREMENT], methods: ['PATCH'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function update(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapRequestPayload(acceptFormat: 'json')] UpdateProjectInput $input,
        RepositoryRepository $repositories,
    ): JsonResponse {
        if (null !== $input->repositoryIds) {
            $members = [];

            foreach ($input->repositoryIds as $id) {
                $repository = $repositories->find(RepositoryId::fromString($id, 'repositoryIds')) ?? throw new NotFound('The repository does not exist.');
                $members[$repository->getId()->toString()] = $repository;
            }

            foreach ($members as $repository) {
                $owner = $repository->getProject();

                if (null !== $owner && !$owner->getId()->equals($project->getId())) {
                    throw InvalidInput::field('repositoryIds', "The repository {$repository->getName()->toString()} belongs to the project {$owner->getName()}.");
                }
            }

            foreach ($repositories->findBy(['project' => $project]) as $member) {
                $member->assignProject(null);
            }

            foreach ($members as $repository) {
                $repository->assignProject($project);
            }
        }

        if (null !== $input->name) {
            $project->rename(trim($input->name));
        }

        if (null !== $input->approvalMode) {
            $project->setApprovalMode($input->approvalMode);
        }

        if (null !== $input->approvalGuidelines) {
            $project->setApprovalGuidelines($input->approvalGuidelines);
        }

        if (null !== $input->yoloMode) {
            $project->setYoloMode($input->yoloMode);
        }

        $this->entityManager->flush();

        return $this->json($this->issues->projectView($project));
    }
}
