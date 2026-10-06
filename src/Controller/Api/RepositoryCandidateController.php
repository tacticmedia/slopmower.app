<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\CandidateList;
use App\Controller\Api\Input\CandidateListQuery;
use App\Domain\Repository\RepositorySource;
use App\Entity\User;
use App\GitHub\GitHubApp;
use App\Repository\GitHubCandidateRepository;
use App\Repository\RepositoryRepository;
use App\Security\CurrentActor;
use App\Source\LocalRepositoryDiscovery;
use App\Source\LocalRepositoryListing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RepositoryCandidateController extends AbstractController
{
    /**
     * R2. The priority puts the route before /api/v1/repositories/{repositoryId}. GitHub candidates come from the stored
     * list that each sign-in replaces and the installation webhooks update, local candidates from a new scan on every
     * call. The filters apply before the 1,000-item limit, which GitHub candidates fill first.
     */
    #[Route('/api/v1/repositories/candidates', name: 'api_repository_candidates', methods: ['GET'], priority: 1)]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function __invoke(
        RepositoryRepository $repositories,
        GitHubCandidateRepository $candidates,
        LocalRepositoryDiscovery $discovery,
        GitHubApp $gitHubApp,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        CandidateListQuery $query = new CandidateListQuery(),
    ): JsonResponse {
        // Not #[CurrentUser]: argument resolution runs before #[IsGranted] answers a token caller.
        $user = $this->getUser();
        \assert($user instanceof User);
        $githubConnectedIds = [];
        $localConnectedIds = [];

        foreach ($repositories->findAll() as $repository) {
            match ($repository->getSource()) {
                RepositorySource::Github => $githubConnectedIds[(int) $repository->getGithubRepositoryId()] = $repository->getId(),
                RepositorySource::Local => $localConnectedIds[(string) $repository->getLocalDirectory()] = $repository->getId(),
            };
        }

        $limit = LocalRepositoryDiscovery::MAX_CANDIDATES;
        $q = $query->q;
        $githubCandidates = RepositorySource::Local === $query->source
            ? []
            : $candidates->search($q, $query->includeConnected ? [] : array_keys($githubConnectedIds), $limit + 1);
        $githubTruncated = count($githubCandidates) > $limit;
        $githubCandidates = array_slice($githubCandidates, 0, $limit);
        $listing = RepositorySource::Github === $query->source
            ? new LocalRepositoryListing([], false)
            : $discovery->list(
                static fn (string $directory): bool => (null === $q || false !== mb_stripos($directory, $q)) && ($query->includeConnected || !isset($localConnectedIds[$directory])),
                $limit - count($githubCandidates),
            );

        return $this->json(new CandidateList(
            githubCandidates: $githubCandidates,
            githubConnectedRepositoryIds: $githubConnectedIds,
            localCandidates: $listing->candidates,
            localConnectedRepositoryIds: $localConnectedIds,
            truncated: $githubTruncated || $listing->truncated,
            localEnabled: $discovery->isEnabled(),
            githubListedAt: $candidates->lastListedAt(),
            githubConfigureUrl: $gitHubApp->installUrlFor($user),
        ));
    }
}
