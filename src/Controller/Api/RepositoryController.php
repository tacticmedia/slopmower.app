<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\ConnectLocalRepository;
use App\Application\FetchRepository;
use App\Application\Query\RepositoryQuery;
use App\Controller\Api\Input\ConnectRepositoryInput;
use App\Controller\Api\Input\RepositoryListQuery;
use App\Domain\Error\NotFound;
use App\Domain\Repository\RepositorySource;
use App\Entity\Repository;
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
 * R1, R3, R4, and R5 of docs/specs/rest-api.md section 4. A path ID that the SQL filter hides resolves to 404.
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
    ): JsonResponse {
        if (RepositorySource::Local !== $input->source || null === $input->directory) {
            throw new NotFound('The GitHub repository is not a candidate.');
        }

        $connected = $connectLocalRepository->connect($input->directory);
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

    #[Route('/{repositoryId}/fetch', name: 'fetch', methods: ['POST'])]
    public function fetch(#[MapEntity(id: 'repositoryId')] Repository $repository, FetchRepository $fetchRepository): JsonResponse
    {
        $fetchRepository->request($repository->getId());

        return $this->json($this->repositories->get($repository->getId()), Response::HTTP_ACCEPTED);
    }
}
