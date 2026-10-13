<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryChanged;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\RepositorySource;
use App\Entity\GitHubAccountType;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\Entity\Repository;
use App\Entity\User;
use App\GitHub\GitHubRequestFailed;
use App\GitHub\InstallationTokenProvider;
use App\GitHub\Payload\InstallationPayload;
use App\GitHub\Payload\RepositoryPayload;
use App\Repository\GitHubCandidateRepository;
use App\Repository\GitHubInstallationRepository;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use HWI\Bundle\OAuthBundle\Security\Http\Authenticator\Passport\Badge\OAuthTokenBadge;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\WebLink\HttpHeaderParser;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The sign-in sync of docs/specs/rest-api.md R2. The OAuthTokenBadge keeps the user token, which
 * {@see \App\Security\DropOAuthTokenListener} removes from the session token. It stays in local variables.
 */
#[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class InstallationSync
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly DenormalizerInterface $denormalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly GitHubCandidateRepository $candidates,
        private readonly GitHubInstallationRepository $installations,
        private readonly RepositoryRepository $repositories,
        private readonly FetchRepository $fetchRepository,
        private readonly TenantContext $tenantContext,
        private readonly GitHubRepositoriesLock $repositoriesLock,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $badge = $event->getPassport()->getBadge(OAuthTokenBadge::class);
        $user = $event->getUser();
        $userToken = $badge instanceof OAuthTokenBadge ? $badge->getToken()->getAccessToken() : null;

        if ($user instanceof User && \is_string($userToken) && '' !== $userToken) {
            $this->sync($user, $userToken);
        }
    }

    /**
     * Replaces the installations and candidates of the tenant. A connected repository that the listing lacks loses its
     * access, and one that lost it earlier loses a clone that is left. A failed or partial listing, and a tenant that is
     * being deleted, change nothing.
     */
    public function sync(User $user, #[\SensitiveParameter] string $userToken): void
    {
        $tenantId = $user->getTenantId();

        try {
            [$installations, $candidates] = $this->list($tenantId, $userToken);
        } catch (GitHubRequestFailed|HttpClientException|SerializerException $failure) {
            $this->logger->warning('The GitHub installations of user {userId} could not be listed.', ['userId' => $user->getId()->toString(), 'exception' => $failure]);

            return;
        }

        // The firewall authenticates before TenantContextListener runs.
        $this->tenantContext->set($tenantId);
        [$lostAccess, $removedEarlier, $renamed] = $this->repositoriesLock->run($tenantId, fn (): array => $this->record($installations, $candidates)) ?? [[], [], []];

        foreach ($lostAccess as $repository) {
            $this->fetchRepository->removeAccess($repository);
        }

        foreach ($removedEarlier as $repository) {
            $this->fetchRepository->deleteLeftClone($repository);
        }

        foreach ($renamed as $repository) {
            $this->dispatcher->dispatch(new RepositoryChanged($tenantId, $repository->getId()));
        }
    }

    /**
     * @param list<GitHubInstallation> $installations
     * @param list<GitHubCandidate>    $candidates
     *
     * @return array{list<Repository>, list<Repository>, list<Repository>} The active and removed repositories that the listing lacks, and the renamed ones
     */
    private function record(array $installations, array $candidates): array
    {
        $this->candidates->deleteAll();
        $this->installations->deleteAll();
        $candidatesById = [];

        foreach ($installations as $installation) {
            $this->entityManager->persist($installation);
        }

        foreach ($candidates as $candidate) {
            $this->entityManager->persist($candidate);
            $candidatesById[$candidate->getGithubRepositoryId()] = $candidate;
        }

        $lostAccess = [];
        $removedEarlier = [];
        $renamed = [];

        foreach ($this->repositories->findBy(['source' => RepositorySource::Github]) as $repository) {
            $candidate = $candidatesById[(int) $repository->getGithubRepositoryId()] ?? null;

            if (null === $candidate) {
                if (RepositoryAccess::Active === $repository->getAccess()) {
                    $lostAccess[] = $repository;
                } else {
                    $removedEarlier[] = $repository;
                }

                continue;
            }

            $name = $this->repositories->nameAfterRename($repository, $candidate->getFullName());

            if ($name->toString() !== $repository->getName()->toString()) {
                $renamed[] = $repository;
            }

            $repository->recordGitHubListing($name, $candidate->getInstallationId(), $candidate->isPrivate());
        }

        $this->entityManager->flush();

        return [$lostAccess, $removedEarlier, $renamed];
    }

    /**
     * @return array{list<GitHubInstallation>, list<GitHubCandidate>}
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     * @throws SerializerException
     */
    private function list(TenantId $tenantId, #[\SensitiveParameter] string $userToken): array
    {
        $listedAt = $this->clock->now();
        $installations = [];
        $candidates = [];

        foreach ($this->items('user/installations', 'installations', $userToken) as $item) {
            $targetType = \is_array($item) && \is_string($item['target_type'] ?? null)
                ? GitHubAccountType::tryFrom($item['target_type'])
                : throw new GitHubRequestFailed('GitHub listed an installation without a target type.');

            // An enterprise installation holds no repositories, and its account has no login.
            if (null === $targetType) {
                continue;
            }

            $installation = $this->denormalizer->denormalize($item, InstallationPayload::class);
            $installations[] = new GitHubInstallation($tenantId, $installation->id, $installation->account->login, $targetType, $installation->suspendedAt, $installation->permissions);

            foreach ($this->items("user/installations/{$installation->id}/repositories", 'repositories', $userToken) as $repositoryItem) {
                $repository = $this->denormalizer->denormalize($repositoryItem, RepositoryPayload::class);

                try {
                    $name = RepositoryName::github($repository->fullName);
                } catch (InvalidInput $invalid) {
                    throw new GitHubRequestFailed("GitHub listed the invalid repository name {$repository->fullName}.", 0, $invalid);
                }

                $candidates[] = new GitHubCandidate($tenantId, $repository->id, $installation->id, $installation->account->login, $name, $repository->private, $repository->defaultBranch, $repository->htmlUrl, $listedAt);
            }
        }

        return [$installations, $candidates];
    }

    /**
     * Every page, through the `next` links of the `Link` header.
     *
     * @return list<mixed>
     *
     * @throws GitHubRequestFailed
     * @throws HttpClientException
     */
    private function items(string $path, string $listKey, #[\SensitiveParameter] string $userToken): array
    {
        $url = InstallationTokenProvider::API.$path.'?per_page='.self::PAGE_SIZE;
        $items = [];

        while (null !== $url) {
            $response = $this->githubClient->request('GET', $url, ['auth_bearer' => $userToken, 'headers' => InstallationTokenProvider::API_HEADERS]);
            $page = $response->toArray()[$listKey] ?? null;

            if (!\is_array($page) || !array_is_list($page)) {
                throw new GitHubRequestFailed("The GitHub response of {$path} has no {$listKey} list.");
            }

            $items = [...$items, ...$page];
            $url = self::nextPage($response->getHeaders()['link'] ?? []);
        }

        return $items;
    }

    /**
     * @param list<string> $linkHeaders
     *
     * @throws GitHubRequestFailed A next page outside the GitHub API
     */
    private static function nextPage(array $linkHeaders): ?string
    {
        foreach (new HttpHeaderParser()->parse($linkHeaders)->getLinksByRel('next') as $link) {
            return str_starts_with($link->getHref(), InstallationTokenProvider::API) ? $link->getHref() : throw new GitHubRequestFailed('GitHub linked a next page outside its API.');
        }

        return null;
    }
}
