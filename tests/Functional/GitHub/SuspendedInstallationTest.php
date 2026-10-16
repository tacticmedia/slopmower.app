<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Domain\Identity\TenantId;
use App\Domain\Repository\SyncState;
use App\Message\CheckRunJob;
use App\Message\GitHubInstallationSuspensionJob;
use App\Messenger\TenantStamp;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LinkedPullRequests;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SuspendedInstallationTest extends KernelTestCase
{
    use EnvironmentVariables;
    use GitHubRows;
    use LinkedPullRequests;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('A suspended installation fails the clone with lastError "GitHub App installation suspended" and requests no token')]
    public function testSuspendedInstallationFailsTheFetch(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource('acme/billing');
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeInstallation($tenantId, suspendedAt: new \DateTimeImmutable('2026-10-01 08:00:00'));
        self::storeCandidate($tenantId);
        $repository = self::connectGitHub($tenantId);
        $transport = self::getContainer()->get('github.client.mock_transport');

        self::runQueuedMessages();

        $failed = self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId());
        self::assertSame(SyncState::Failed, $failed?->getSyncState());
        self::assertSame('GitHub App installation suspended', $failed->getLastError());
        self::assertSame(0, $transport->getRequestsCount());
    }

    #[TestDox('An unsuspend queues the read of each linked pull request, also with a read state, and the check run of each record')]
    public function testUnsuspendQueuesReadsAndCheckRuns(): void
    {
        [$user, $recordIds] = self::linkPullRequests(self::CHECKS, new \DateTimeImmutable('2026-10-01 08:00:00'));

        self::recordSuspension($user->getTenantId(), null);

        self::assertSame([42, 43, 44], self::queuedReads());
        self::assertEqualsCanonicalizing($recordIds, array_map(static fn (CheckRunJob $job): string => $job->pullRequestId->toString(), self::queued(CheckRunJob::class)));
    }

    #[TestDox('A suspend queues nothing')]
    public function testSuspendQueuesNothing(): void
    {
        $tenantId = self::linkPullRequests(self::CHECKS)[0]->getTenantId();

        self::recordSuspension($tenantId, new \DateTimeImmutable('2026-10-01 08:00:00'));

        self::assertSame([], self::asyncTransport()->getSent());
    }

    /**
     * Handles the job as the worker does, without running the jobs that it queues.
     */
    private static function recordSuspension(TenantId $tenantId, ?\DateTimeImmutable $suspendedAt): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new GitHubInstallationSuspensionJob(5551, $suspendedAt), [new TenantStamp($tenantId), new ReceivedStamp('async')]);
    }
}
