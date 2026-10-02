<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\ConnectGitHubRepository;
use App\Application\ConnectLocalRepository;
use App\Application\FetchRepository;
use App\Application\Query\RepositoryQuery;
use App\Controller\Api\Input\ConfirmQuery;
use App\Controller\Api\Input\ConnectRepositoryInput;
use App\Controller\Api\Input\RepositoryListQuery;
use App\Domain\Error\InvalidInput;
use App\Domain\Repository\RepositorySource;
use App\Entity\Repository;
use App\Entity\User;
use App\EventStore\CommandExecutor;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Message\DeleteCloneJob;
use App\Repository\DeletionSql;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * R1 and R3 to R6 of docs/specs/rest-api.md section 4. A path ID that the SQL filter hides resolves to 404.
 */
#[Route('/api/v1/repositories', name: 'api_repository_')]
final class RepositoryController extends AbstractController
{
    public function __construct(private readonly RepositoryQuery $repositories)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        RepositoryListQuery $query = new RepositoryListQuery(),
    ): JsonResponse {
        return $this->json($this->repositories->list($query->name, $query->source, $query->limit, $query->cursor));
    }

    #[Route('', name: 'connect', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function connect(
        #[MapRequestPayload(acceptFormat: 'json')] ConnectRepositoryInput $input,
        ConnectLocalRepository $connectLocalRepository,
        ConnectGitHubRepository $connectGitHubRepository,
    ): JsonResponse {
        $connected = RepositorySource::Github === $input->source
            ? $connectGitHubRepository->connect($input->githubRepositoryId ?? throw new \LogicException('The input validation requires the ID.'))
            : $connectLocalRepository->connect($input->directory ?? throw new \LogicException('The input validation requires the directory.'));
        $id = $connected->repository->getId();
        $view = $this->repositories->get($id);

        return $connected->created
            ? $this->json($view, Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_repository_get', ['repositoryId' => $id])])
            : $this->json($view);
    }

    #[Route('/{repositoryId}', name: 'get', methods: ['GET'])]
    public function get(#[MapEntity(id: 'repositoryId')] Repository $repository): JsonResponse
    {
        return $this->json($this->repositories->get($repository->getId()));
    }

    /**
     * The rows go in the request, so no query needs a "being deleted" filter. The job deletes the clone after a running
     * fetch. A new connect of the same source gets a new ID, so it works before the job runs.
     */
    #[Route('/{repositoryId}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function delete(
        #[MapEntity(id: 'repositoryId')] Repository $repository,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ConfirmQuery $query,
        CommandExecutor $executor,
        DeletionSql $deletion,
        LivePublisher $publisher,
        LiveTopics $topics,
        MessageBusInterface $bus,
    ): Response {
        if ($repository->getName()->toString() !== $query->confirm) {
            throw InvalidInput::field('confirm', 'Must equal the name of the repository.');
        }

        $tenantId = $repository->getTenantId();
        $id = $repository->getId();
        $executor->delete($tenantId, static fn () => $deletion->deleteRepository($tenantId, $id));
        $publisher->publish($topics->repositories($tenantId), ['type' => 'repository_deleted', 'repositoryId' => $id->toString()]);
        $bus->dispatch(new DeleteCloneJob($tenantId, $id));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/{repositoryId}/fetch', name: 'fetch', methods: ['POST'])]
    public function fetch(#[MapEntity(id: 'repositoryId')] Repository $repository, FetchRepository $fetchRepository): JsonResponse
    {
        $fetchRepository->request($repository->getId());

        return $this->json($this->repositories->get($repository->getId()), Response::HTTP_ACCEPTED);
    }
}
