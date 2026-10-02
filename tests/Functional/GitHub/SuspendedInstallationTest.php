<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Domain\Repository\SyncState;
use App\Repository\RepositoryRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SuspendedInstallationTest extends KernelTestCase
{
    use EnvironmentVariables;
    use GitHubRows;
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
        $transport = self::getContainer()->get('http_client.transport');

        self::runQueuedMessages();

        $failed = self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId());
        self::assertSame(SyncState::Failed, $failed?->getSyncState());
        self::assertSame('GitHub App installation suspended', $failed->getLastError());
        self::assertSame(0, $transport->getRequestsCount());
    }
}
