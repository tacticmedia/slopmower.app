<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\ConnectedRepository;
use App\Application\FetchRepository;
use App\Application\InstallationSync;
use App\Application\Query\RepositoryQuery;
use App\Controller\Api\Input\ConfirmQuery;
use App\Controller\Api\Input\ConnectRepositoryInput;
use App\Controller\Api\Input\RepositoryListQuery;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Repository\RepositoryName;
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
use App\Source\LocalRepositoryDiscovery;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * R1 and R3 to R6 of docs/specs/rest-api.md section 4. A path ID that the SQL filter hides resolves to 404.
 */
#[Route('/api/v1/repositories', name: 'api_repository_')]
final class RepositoryController extends AbstractController
{
    public function __construct(
        private readonly RepositoryQuery $repositories,
        private readonly RepositoryRepository $repositoryRows,
        private readonly GitHubCandidateRepository $candidates,
        private readonly LocalRepositoryDiscovery $discovery,
        private readonly FetchRepository $fetchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly LockFactory $lockFactory,
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
            : $this->connectLocal($input->directory ?? throw new \LogicException('The input validation requires the directory.'));
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

    /**
     * A new repository is `cloning`, and its clone job is queued after the row commits.
     *
     * @throws NotFound     The ID is not a candidate of the tenant
     * @throws InvalidInput Another repository of the tenant has the name, in any case
     */
    private function connectGitHub(int $githubRepositoryId): ConnectedRepository
    {
        $tenantId = $this->tenantContext->get();
        $lock = $this->lockFactory->createLock(InstallationSync::lockKey($tenantId), ttl: null);
        $lock->acquire(true);

        try {
            $candidate = $this->candidates->findByGitHubId($githubRepositoryId) ?? throw new NotFound('The GitHub repository is not a candidate.');
            $existing = $this->repositoryRows->findGitHub($githubRepositoryId);

            if (null !== $existing) {
                return new ConnectedRepository($existing, false);
            }

            $name = $candidate->getFullName();

            if (null !== $this->repositoryRows->findGitHubByName($name)) {
                throw InvalidInput::field('githubRepositoryId', "Another connected repository has the name {$name}.");
            }

            $repository = Repository::gitHub($tenantId, $name, $githubRepositoryId, $candidate->getInstallationId(), $candidate->isPrivate(), $this->clock->now());
            $this->entityManager->persist($repository);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $this->fetchRepository->queueClone($repository);

        return new ConnectedRepository($repository, true);
    }

    /**
     * A new repository is `cloning`, and its clone job is queued after the row commits.
     *
     * @throws InvalidInput Local repositories are off, or the name is not a direct child name
     * @throws NotFound     The directory is not a local candidate
     */
    private function connectLocal(string $directory): ConnectedRepository
    {
        if (!$this->discovery->isEnabled()) {
            throw InvalidInput::field('source', 'Local repositories are off on this instance.');
        }

        $name = RepositoryName::local($directory, 'directory');
        $this->discovery->find($directory) ?? throw new NotFound('The directory is not a local repository candidate.');
        $tenantId = $this->tenantContext->get();
        // A second concurrent connect waits, then finds the row, instead of failing on the unique index.
        $lock = $this->lockFactory->createLock("connect-local:{$tenantId}:{$directory}", ttl: null);
        $lock->acquire(true);

        try {
            $existing = $this->repositoryRows->findLocal($directory);

            if (null !== $existing) {
                return new ConnectedRepository($existing, false);
            }

            $repository = Repository::local($tenantId, $name, $directory, $this->clock->now());
            $this->entityManager->persist($repository);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $this->fetchRepository->queueClone($repository);

        return new ConnectedRepository($repository, true);
    }
}
