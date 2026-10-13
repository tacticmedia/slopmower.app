<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\PullRequestId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\CheckRunOutput;
use App\Entity\GitHubAccountType;
use App\Entity\SignInMethod;
use App\Entity\TenantOwned;
use App\GitHub\Payload\InstallationPayload;
use App\GitHub\Payload\MarketplacePurchaseAction;
use App\GitHub\Payload\MarketplacePurchasePayload;
use App\GitHub\Payload\PullRequestPayload;
use App\GitHub\Payload\RepositoryPayload;
use App\Message\CheckRunJob;
use App\Message\GitHubInstallationCreatedJob;
use App\Message\GitHubInstallationDeletedJob;
use App\Message\GitHubInstallationPermissionsJob;
use App\Message\GitHubInstallationSuspensionJob;
use App\Message\GitHubPullRequestJob;
use App\Message\GitHubPushJob;
use App\Message\GitHubRepositoriesAddedJob;
use App\Message\GitHubRepositoriesRemovedJob;
use App\Message\GitHubRepositoryUpdatedJob;
use App\Message\MarketplaceCancellationJob;
use App\Message\MarketplacePurchaseJob;
use App\Message\NeutralCheckRunJob;
use App\Messenger\TenantStamp;
use App\Repository\GitHubCandidateRepository;
use App\Repository\GitHubInstallationRepository;
use App\Repository\PullRequestRepository;
use App\Repository\RepositoryRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Tenancy\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Routes a verified delivery to one job per tenant, docs/specs/webhooks-and-live-updates.md section 1. It reads across
 * tenants and writes no tenant row. The jobs run under the tenant of their TenantStamp. A job for no tenant gets none.
 */
#[AsRemoteEventConsumer('github')]
final class GitHubWebhookConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly WebhookDeliverySql $deliveries,
        private readonly TenantFilter $tenantFilter,
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositories,
        private readonly GitHubInstallationRepository $installations,
        private readonly GitHubCandidateRepository $candidates,
        private readonly PullRequestRepository $pullRequests,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly DenormalizerInterface $denormalizer,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The delivery is recorded after its jobs are queued, so a failed run leaves no row and its retry queues them
     * again. Every job is idempotent.
     */
    public function consume(RemoteEvent $event): void
    {
        if ($this->deliveries->isRecorded($event->getId())) {
            return;
        }

        foreach ($this->tenantJobs($event) as [$tenantId, $job]) {
            $this->bus->dispatch($job, null === $tenantId ? [] : [new TenantStamp($tenantId)]);
        }

        $this->deliveries->record($event->getId(), $this->clock->now());
    }

    /**
     * A tenant that is being deleted gets no job.
     *
     * @return list<array{TenantId|null, object}>
     */
    private function tenantJobs(RemoteEvent $event): array
    {
        try {
            return $this->tenantFilter->suspend(fn (): array => array_values(array_filter(
                $this->route($event->getName(), $event->getPayload()),
                fn (array $tenantJob): bool => null === $tenantJob[0] || $this->tenants->isActive($tenantJob[0]),
            )));
        } catch (SerializerException $invalid) {
            throw self::malformed('an object of an unexpected shape', $invalid);
        } finally {
            // The SQL filter does not cover identity-map hits.
            $this->entityManager->clear();
        }
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId|null, object}>
     */
    private function route(string $name, array $payload): array
    {
        $action = $payload['action'] ?? null;

        return match ($name) {
            'github.push' => $this->push($payload),
            'github.installation' => $this->installation($action, $payload),
            'github.installation_repositories' => match ($action) {
                'added' => $this->repositoriesAdded($payload),
                'removed' => $this->repositoriesRemoved(self::repositoryIds($payload, 'repositories_removed')),
                default => [],
            },
            'github.repository' => $this->repository($action, $payload),
            'github.pull_request' => $this->pullRequest($action, $payload),
            'github.check_run' => 'rerequested' === $action ? $this->checkRunRerequested($payload) : [],
            'github.github_app_authorization' => $this->authorization($action, $payload),
            'github.marketplace_purchase' => $this->marketplacePurchase($action, $payload),
            default => [],
        };
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function push(array $payload): array
    {
        $githubRepositoryId = $this->denormalizer->denormalize($payload['repository'] ?? null, RepositoryPayload::class)->id;
        $repositories = $this->repositories->findBy(['githubRepositoryId' => $githubRepositoryId, 'access' => RepositoryAccess::Active]);

        return self::jobs(self::tenantIds(...$repositories), new GitHubPushJob($githubRepositoryId));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function installation(mixed $action, array $payload): array
    {
        if ('created' === $action) {
            return $this->installationCreated($payload);
        }

        $installationId = self::int($payload, 'installation', 'id');
        $installations = $this->installations->findBy(['installationId' => $installationId]);

        return match ($action) {
            'deleted' => self::jobs(
                self::tenantIds(
                    ...$installations,
                    ...$this->candidates->findBy(['installationId' => $installationId]),
                    ...$this->repositories->findBy(['githubInstallationId' => $installationId]),
                ),
                new GitHubInstallationDeletedJob($installationId),
            ),
            'suspend', 'unsuspend' => self::jobs(
                self::tenantIds(...$installations),
                new GitHubInstallationSuspensionJob($installationId, 'suspend' === $action ? $this->clock->now() : null),
            ),
            // A tenant has only user and organization installations, whose payload denormalizes.
            'new_permissions_accepted' => [] === $installations ? [] : self::jobs(
                self::tenantIds(...$installations),
                new GitHubInstallationPermissionsJob($installationId, $this->denormalizer->denormalize($payload['installation'], InstallationPayload::class)->permissions),
            ),
            default => [],
        };
    }

    /**
     * Without a user of the sender, the next sign-in records the installation.
     *
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function installationCreated(array $payload): array
    {
        $targetType = self::value($payload, 'installation', 'target_type');
        // An enterprise installation holds no repositories, and its account has no login.
        $accountType = \is_string($targetType) ? GitHubAccountType::tryFrom($targetType) : null;
        $user = null === $accountType ? null : $this->users->findOneBy(['githubUserId' => self::int($payload, 'sender', 'id')]);

        if (null === $accountType || null === $user) {
            return [];
        }

        $installation = $this->denormalizer->denormalize($payload['installation'], InstallationPayload::class);

        return [[$user->getTenantId(), new GitHubInstallationCreatedJob($installation->id, $installation->account->id, $installation->account->login, $accountType, $installation->suspendedAt, $installation->permissions)]];
    }

    /**
     * Organization installations refresh at the next sign-in, because user access cannot be checked without the user
     * token.
     *
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function repositoriesAdded(array $payload): array
    {
        $user = 'User' === self::value($payload, 'installation', 'account', 'type')
            ? $this->users->findOneBy(['githubUserId' => self::int($payload, 'installation', 'account', 'id')])
            : null;

        if (null === $user) {
            return [];
        }

        $repositories = [];

        foreach (self::items($payload, 'repositories_added') as $item) {
            $repositories[self::int($item, 'id')] = self::name(self::value($item, 'full_name'));
        }

        if ([] === $repositories) {
            return [];
        }

        $installation = $this->denormalizer->denormalize($payload['installation'], InstallationPayload::class);

        return [[$user->getTenantId(), new GitHubRepositoriesAddedJob($installation->id, $installation->account->login, $repositories)]];
    }

    /**
     * Tenants that connected a repository, and tenants that only list it as a candidate.
     *
     * @param list<int> $githubRepositoryIds
     *
     * @return list<array{TenantId, object}>
     */
    private function repositoriesRemoved(array $githubRepositoryIds): array
    {
        if ([] === $githubRepositoryIds) {
            return [];
        }

        $tenantIds = self::tenantIds(
            ...$this->repositories->findBy(['githubRepositoryId' => $githubRepositoryIds]),
            ...$this->candidates->findBy(['githubRepositoryId' => $githubRepositoryIds]),
        );

        return self::jobs($tenantIds, new GitHubRepositoriesRemovedJob($githubRepositoryIds));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function repository(mixed $action, array $payload): array
    {
        $repository = $this->denormalizer->denormalize($payload['repository'] ?? null, RepositoryPayload::class);

        if ('deleted' === $action) {
            return $this->repositoriesRemoved([$repository->id]);
        }

        $renamed = \in_array($action, ['renamed', 'transferred'], true);
        $job = new GitHubRepositoryUpdatedJob($repository->id, self::name($repository->fullName), $repository->private, $renamed);

        return self::jobs(self::tenantIds(...$this->repositories->findBy(['githubRepositoryId' => $repository->id])), $job);
    }

    /**
     * Only tenants that link the number have a record of it. Each other tenant that checks every pull request of the
     * repository gets its own neutral run, so its check does not block a pull request that it does not link.
     *
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function pullRequest(mixed $action, array $payload): array
    {
        $githubRepositoryId = $this->denormalizer->denormalize($payload['repository'] ?? null, RepositoryPayload::class)->id;
        $pullRequest = $this->denormalizer->denormalize($payload['pull_request'] ?? null, PullRequestPayload::class);
        $linking = self::tenantIds(...$this->pullRequests->findByGitHubRepository($githubRepositoryId, $pullRequest->number));
        $jobs = self::jobs($linking, new GitHubPullRequestJob($githubRepositoryId, $pullRequest));

        if (!\in_array($action, ['opened', 'reopened', 'synchronize'], true)) {
            return $jobs;
        }

        $linkingIds = array_map(static fn (TenantId $tenantId): string => $tenantId->toString(), $linking);

        foreach ($this->repositories->findBy(['githubRepositoryId' => $githubRepositoryId, 'access' => RepositoryAccess::Active, 'checkEveryPullRequest' => true]) as $repository) {
            if (!\in_array($repository->getTenantId()->toString(), $linkingIds, true)) {
                $jobs[] = [$repository->getTenantId(), new NeutralCheckRunJob($repository->getId(), $pullRequest->number, self::sha($pullRequest->headSha))];
            }
        }

        return $jobs;
    }

    /**
     * A run of another App has another `external_id`, or none. A record run gets a new run. A neutral run, and a run
     * whose record is gone, for example after a deletion of the repository data or of the tenant, gets the neutral run
     * of its tenant: the one that its `external_id` names, else the users with the login of its name.
     *
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function checkRunRerequested(array $payload): array
    {
        $githubRepositoryId = self::int($payload, 'repository', 'id');
        $externalId = self::value($payload, 'check_run', 'external_id');
        $externalId = \is_string($externalId) ? $externalId : '';
        $pullRequest = Ulid::isValid($externalId) ? $this->pullRequests->find(PullRequestId::fromString($externalId)) : null;

        if (null !== $pullRequest && $pullRequest->getRepository()->getGithubRepositoryId() === $githubRepositoryId) {
            return [[$pullRequest->getTenantId(), new CheckRunJob($pullRequest->getId(), newRun: true)]];
        }

        $tenantId = CheckRunOutput::tenantOf($externalId);
        $number = self::value($payload, 'check_run', 'pull_requests', '0', 'number');
        $jobs = [];

        foreach (null !== $tenantId && $this->tenants->isActive($tenantId) ? [$tenantId] : $this->tenantsOfCheckName(self::value($payload, 'check_run', 'name')) as $named) {
            $repository = $this->repositories->findOneBy(['tenantId' => $named, 'githubRepositoryId' => $githubRepositoryId, 'access' => RepositoryAccess::Active, 'checkEveryPullRequest' => true]);

            if (null !== $repository) {
                $jobs[] = [$named, new NeutralCheckRunJob($repository->getId(), \is_int($number) ? $number : null, self::sha(self::value($payload, 'check_run', 'head_sha')))];
            }
        }

        return $jobs;
    }

    /**
     * A renamed login can pass to another user before the next sign-in of either, so it can name two tenants.
     *
     * @return list<TenantId>
     */
    private function tenantsOfCheckName(mixed $name): array
    {
        $login = \is_string($name) ? CheckRunOutput::loginOf($name) : null;

        return null === $login ? [] : self::tenantIds(...$this->users->findBy(['login' => $login, 'signInMethod' => SignInMethod::GitHub]));
    }

    /**
     * The server never stores the user token, so nothing is revoked.
     *
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId, object}>
     */
    private function authorization(mixed $action, array $payload): array
    {
        if ('revoked' === $action) {
            $this->logger->info('GitHub user {githubUserId} revoked the authorization of the GitHub App.', ['githubUserId' => self::value($payload, 'sender', 'id')]);
        }

        return [];
    }

    /**
     * The record job, and for `cancelled` of a user account the cancellation job of the tenant of that user.
     *
     * @param array<mixed> $payload
     *
     * @return list<array{TenantId|null, object}>
     */
    private function marketplacePurchase(mixed $action, array $payload): array
    {
        $action = \is_string($action) ? MarketplacePurchaseAction::tryFrom($action) : null;

        if (null === $action) {
            return [];
        }

        $accountId = self::int($payload, 'marketplace_purchase', 'account', 'id');
        $purchase = $this->denormalizer->denormalize($payload['marketplace_purchase'], MarketplacePurchasePayload::class);
        $jobs = [[null, new MarketplacePurchaseJob($accountId, $action, $purchase, $this->denormalizer->denormalize($payload['effective_date'] ?? null, \DateTimeImmutable::class))]];
        $user = MarketplacePurchaseAction::Cancelled === $action && 'User' === self::value($payload, 'marketplace_purchase', 'account', 'type')
            ? $this->users->findOneBy(['githubUserId' => $accountId])
            : null;

        if (null !== $user) {
            $jobs[] = [$user->getTenantId(), Envelope::wrap(new MarketplaceCancellationJob($accountId), [DelayStamp::delayFor(new \DateInterval(MarketplaceCancellationJob::DELAY))])];
        }

        return $jobs;
    }

    /**
     * @return list<TenantId> Without duplicates
     */
    private static function tenantIds(TenantOwned ...$rows): array
    {
        $tenantIds = [];

        foreach ($rows as $row) {
            $tenantIds[$row->getTenantId()->toString()] = $row->getTenantId();
        }

        return array_values($tenantIds);
    }

    /**
     * @param list<TenantId> $tenantIds
     *
     * @return list<array{TenantId, object}>
     */
    private static function jobs(array $tenantIds, object $job): array
    {
        return array_map(static fn (TenantId $tenantId): array => [$tenantId, $job], $tenantIds);
    }

    /**
     * @param array<mixed> $payload
     */
    private static function value(array $payload, string ...$keys): mixed
    {
        $value = $payload;

        foreach ($keys as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function int(array $payload, string ...$keys): int
    {
        $value = self::value($payload, ...$keys);

        return \is_int($value) ? $value : throw self::malformed('no integer '.implode('.', $keys));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array<mixed>>
     */
    private static function items(array $payload, string $key): array
    {
        $items = $payload[$key] ?? null;

        $isList = \is_array($items) && array_is_list($items) && array_all($items, static fn (mixed $item): bool => \is_array($item));

        return $isList ? $items : throw self::malformed("no {$key} list");
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<int>
     */
    private static function repositoryIds(array $payload, string $key): array
    {
        return array_map(static fn (array $item): int => self::int($item, 'id'), self::items($payload, $key));
    }

    private static function sha(mixed $sha): CommitSha
    {
        try {
            return CommitSha::fromString(\is_string($sha) ? $sha : '', 'head_sha');
        } catch (InvalidInput $invalid) {
            throw self::malformed('an invalid head SHA', $invalid);
        }
    }

    private static function name(mixed $fullName): RepositoryName
    {
        try {
            return RepositoryName::github(\is_string($fullName) ? $fullName : '');
        } catch (InvalidInput $invalid) {
            throw self::malformed('an invalid repository name', $invalid);
        }
    }

    /**
     * A retry cannot repair the payload, so the worker sends the delivery to the failure transport at once.
     */
    private static function malformed(string $problem, ?\Throwable $previous = null): UnrecoverableMessageHandlingException
    {
        return new UnrecoverableMessageHandlingException("The GitHub delivery has {$problem}.", 0, $previous);
    }
}
