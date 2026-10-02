<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Application\FetchRepository;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\GitHub\GitHubAccountType;
use App\Message\GitHubInstallationCreatedJob;
use App\Messenger\TenantStamp;
use App\Repository\GitHubCandidateRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

final class InstallationWebhookTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use LocalGitHub;
    use PublishedUpdates;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('installation created records the installation for the matching user and publishes candidates_changed')]
    public function testInstallationCreatedRecordsTheInstallation(): void
    {
        $client = static::createClient();
        $sender = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        $other = TenantFixtures::of(self::getContainer())->withGitHubUser(2)->create()->getTenantId();

        self::deliverFixture($client, 'installation.created');
        self::runQueuedMessages();

        self::assertSame(
            [['installation_id' => 5554, 'account_login' => 'octocat', 'account_type' => 'User', 'suspended_at' => null]],
            self::getContainer()->get(Connection::class)->fetchAllAssociative(
                'SELECT installation_id, account_login, account_type, suspended_at FROM github_installation WHERE tenant_id = ?',
                [Ulid::fromString($sender->toString())->toRfc4122()],
            ),
        );
        self::assertSame(0, self::countTenantRows('github_installation', $other));
        self::assertContains(['type' => 'candidates_changed'], self::repositoriesTopicUpdates($sender));
        self::assertSame([], self::repositoriesTopicUpdates($other));
    }

    #[TestDox('A tenant that is being deleted gets no job, and a job queued before the mark writes no row')]
    public function testTenantBeingDeletedGetsNoInstallation(): void
    {
        $client = static::createClient();
        $sender = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE tenant SET deletion_requested_at = now()');

        self::deliverFixture($client, 'installation.created');
        self::runQueuedMessages();
        $routedJobs = array_filter([...self::asyncTransport()->getSent()], static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof GitHubInstallationCreatedJob);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new GitHubInstallationCreatedJob(5554, 'octocat', GitHubAccountType::User, null), [new TenantStamp($sender)]);
        self::runQueuedMessages();

        self::assertSame([], $routedJobs);
        self::assertSame(0, self::countTenantRows('github_installation', $sender));
    }

    #[TestDox('suspend makes fetches fail with repository_unavailable')]
    public function testSuspendMakesFetchesFail(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);

        self::deliverFixture($client, 'installation.suspend');
        self::runQueuedMessages();

        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());

        try {
            self::getContainer()->get(FetchRepository::class)->fetchNow($repository->getId());
            self::fail('The fetch did not fail.');
        } catch (RepositoryUnavailable $unavailable) {
            self::assertSame(RepositoryUnavailableReason::FetchFailed, $unavailable->details()['reason']);
        }

        self::assertSame('GitHub App installation suspended', self::storedRepository($repository)->getLastError());
    }

    #[TestDox('repository renamed updates the name')]
    public function testRepositoryRenamedUpdatesTheName(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);

        self::deliverFixture($client, 'repository.renamed');
        self::runQueuedMessages();

        self::assertSame('acme/billing-service', self::storedRepository($repository)->getName()->toString());
        self::assertContains(['type' => 'repository', 'repositoryId' => $repository->getId()->toString()], self::repositoriesTopicUpdates($repository->getTenantId()));
    }

    #[TestDox('A rename onto the name of another connected repository of the tenant waits')]
    public function testRenameOntoATakenNameWaits(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);
        self::storeCandidate($repository->getTenantId(), 812399, 'Acme/Billing-Service');
        self::connectGitHub($repository->getTenantId(), 812399);

        self::deliverFixture($client, 'repository.renamed', ['repository' => ['private' => false]]);
        self::runQueuedMessages();

        $stored = self::storedRepository($repository);
        self::assertSame(['acme/billing', false], [$stored->getName()->toString(), $stored->isGithubPrivate()]);
    }

    #[TestDox('installation_repositories added lists each repository as a candidate with the default branch that GitHub reports and the time of the last listing')]
    public function testRepositoriesAddedBecomeCandidates(): void
    {
        $client = static::createClient();
        self::mockTime('2026-09-28 07:00:00');
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeCandidate($tenantId);
        self::mockTime('2026-10-01 09:00:00');

        self::deliverFixture($client, 'installation_repositories.added');
        self::runQueuedMessages();

        self::getContainer()->get(TenantContext::class)->set($tenantId);
        $candidate = self::getContainer()->get(GitHubCandidateRepository::class)->findByGitHubId(1296269) ?? self::fail('No candidate.');
        self::assertSame(
            ['octocat/Hello-World', 5552, 'octocat', false, 'master', 'https://github.com/octocat/Hello-World', '2026-09-28 07:00:00'],
            [(string) $candidate->getFullName(), $candidate->getInstallationId(), $candidate->getAccountLogin(), $candidate->isPrivate(), $candidate->getDefaultBranch(), $candidate->getHtmlUrl(), $candidate->getListedAt()->format('Y-m-d H:i:s')],
        );
        self::assertContains(['type' => 'candidates_changed'], self::repositoriesTopicUpdates($tenantId));
    }
}
