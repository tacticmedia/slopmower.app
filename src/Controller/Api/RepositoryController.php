<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\ConnectedRepository;
use App\Application\ConnectLocalRepository;
use App\Application\Event\RepositoryChanged;
use App\Application\FetchRepository;
use App\Application\GitHubRepositoriesLock;
use App\Application\Query\RepositoryQuery;
use App\Application\UsageLimits;
use App\Controller\Api\Input\CheckEveryPullRequestInput;
use App\Controller\Api\Input\ConfirmQuery;
use App\Controller\Api\Input\ConnectRepositoryInput;
use App\Controller\Api\Input\RepositoryListQuery;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\LimitExceeded;
use App\Domain\Error\NotFound;
use App\Domain\Repository\RepositorySource;
use App\Entity\Repository;
use App\Entity\User;
use App\EventStore\CommandExecutor;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Message\DeleteCloneJob;
use App\Repository\DeletionSql;
use App\Repository\GitHubCandidateRepository;
use App\Repository\RepositoryRepository;
use App\Security\CurrentActor;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * R1, R3 to R6, and R8 of docs/specs/rest-api.md section 4. A path ID that the SQL filter hides resolves to 404.
 */
#[Route('/api/v1/repositories', name: 'api_repository_')]
final class RepositoryController extends AbstractController
{
    public function __construct(
        private readonly RepositoryQuery $repositories,
        private readonly RepositoryRepository $repositoryRows,
        private readonly GitHubCandidateRepository $candidates,
        private readonly ConnectLocalRepository $connectLocalRepository,
        private readonly FetchRepository $fetchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly GitHubRepositoriesLock $repositoriesLock,
        private readonly UsageLimits $usageLimits,
        private readonly ClockInterface $clock,
    ) {
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
    public function connect(#[MapRequestPayload(acceptFormat: 'json')] ConnectRepositoryInput $input): JsonResponse
    {
        $connected = RepositorySource::Github === $input->source
            ? $this->connectGitHub($input->githubRepositoryId ?? throw new \LogicException('The input validation requires the ID.'))
            : $this->connectLocalRepository->connect($input->directory ?? throw new \LogicException('The input validation requires the directory.'));
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
    public function fetch(#[MapEntity(id: 'repositoryId')] Repository $repository): JsonResponse
    {
        $this->fetchRepository->request($repository->getId());

        return $this->json($this->repositories->get($repository->getId()), Response::HTTP_ACCEPTED);
    }

    #[Route('/{repositoryId}/check-every-pull-request', name: 'check_every_pull_request', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function checkEveryPullRequest(
        #[MapEntity(id: 'repositoryId')] Repository $repository,
        #[MapRequestPayload(acceptFormat: 'json')] CheckEveryPullRequestInput $input,
        EventDispatcherInterface $dispatcher,
    ): JsonResponse {
        $repository->setCheckEveryPullRequest($input->enabled ?? throw new \LogicException('The input validation requires the value.'));
        $this->entityManager->flush();
        $dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $repository->getId()));

        return $this->json($this->repositories->get($repository->getId()));
    }

    /**
     * A new repository is `cloning`, and its clone job is queued after the row commits.
     *
     * @throws NotFound      The ID is not a candidate of the tenant, or the tenant is being deleted
     * @throws InvalidInput  Another repository of the tenant has the name, in any case
     * @throws LimitExceeded
     */
    private function connectGitHub(int $githubRepositoryId): ConnectedRepository
    {
        $tenantId = $this->tenantContext->get();
        $connected = $this->repositoriesLock->run($tenantId, function () use ($tenantId, $githubRepositoryId): ConnectedRepository {
            $candidate = $this->candidates->findByGitHubId($githubRepositoryId) ?? throw new NotFound('The GitHub repository is not a candidate.');
            $existing = $this->repositoryRows->findGitHub($githubRepositoryId);

            if (null !== $existing) {
                return new ConnectedRepository($existing, false);
            }

            $name = $candidate->getFullName();

            if (null !== $this->repositoryRows->findGitHubByName($name)) {
                throw InvalidInput::field('githubRepositoryId', "Another connected repository has the name {$name}.");
            }

            $this->usageLimits->checkNewRepository($tenantId);

            $repository = Repository::gitHub($tenantId, $name, $githubRepositoryId, $candidate->getInstallationId(), $candidate->isPrivate(), $this->clock->now());
            $this->entityManager->persist($repository);

            return new ConnectedRepository($repository, true);
        }) ?? throw new NotFound();

        if ($connected->created) {
            $this->fetchRepository->queueClone($connected->repository);
        }

        return $connected;
    }
}
