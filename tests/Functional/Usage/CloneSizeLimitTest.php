<?php

declare(strict_types=1);

namespace App\Tests\Functional\Usage;

use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Repository\SyncState;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The size limits of docs/specs/README.md section "Usage limits". Random bytes do not compress, so a file of N bytes
 * makes a pack of about N bytes.
 */
final class CloneSizeLimitTest extends KernelTestCase
{
    use EnvironmentVariables;
    use GitHubRows;
    use LocalGitHub;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private const int MIB = 1_048_576;

    private const string REPOSITORY_SIZE_ERROR = 'The clone is larger than the limit of your plan of 1 MiB per repository (repository_size).';

    #[TestDox('A clone above the size limit of a repository fails, names the limit, and deletes the clone')]
    public function testCloneAboveTheRepositoryLimitFails(): void
    {
        $this->setEnvironmentVariable('APP_PLAN_FREE_REPOSITORY_SIZE_MIB', '1');
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(['random.bin' => random_bytes(2 * self::MIB)]);
        self::enterNewTenant();
        $repositoryId = self::connect()->getId();

        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame([SyncState::Failed, self::REPOSITORY_SIZE_ERROR, null], [$repository->getSyncState(), $repository->getLastError(), $repository->getCloneSizeBytes()]);
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
    }

    #[TestDox('A GitHub clone whose size in the GitHub API is above the limit fails before git runs')]
    public function testGitHubSizeAboveTheLimitFailsBeforeTheClone(): void
    {
        // The source has one small commit. The recorded GitHub answer gives acme/billing 2 MiB.
        $this->setEnvironmentVariable('APP_PLAN_FREE_REPOSITORY_SIZE_MIB', '1');
        $this->useLocalGitHub();
        $this->gitHubSource();
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeInstallation($tenantId);
        self::storeCandidate($tenantId);
        $repositoryId = self::connectGitHub($tenantId)->getId();

        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame([SyncState::Failed, self::REPOSITORY_SIZE_ERROR], [$repository->getSyncState(), $repository->getLastError()]);
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
    }

    #[TestDox('A clone that takes the clones of the tenant above the storage limit fails')]
    public function testCloneAboveTheTenantLimitFails(): void
    {
        $this->setEnvironmentVariable('APP_PLAN_FREE_TENANT_STORAGE_MIB', '1');
        $this->useTemporaryLocalSources();
        $this->newSource('billing')->commit(['random.bin' => random_bytes((int) (0.6 * self::MIB))]);
        $this->newSource('shipping')->commit(['random.bin' => random_bytes((int) (0.6 * self::MIB))]);
        self::enterNewTenant();
        $billingId = self::connect('billing')->getId();
        $shippingId = self::connect('shipping')->getId();

        self::runQueuedMessages();

        self::assertSame(SyncState::Idle, self::freshRepository($billingId)->getSyncState());
        $shipping = self::freshRepository($shippingId);
        self::assertSame(SyncState::Failed, $shipping->getSyncState());
        self::assertSame('The clones of your repositories are larger than the limit of your plan of 1 MiB in total (tenant_storage).', $shipping->getLastError());
    }

    #[TestDox('A fetch that passes the size limit keeps the clone, and each later fetch fails with fetch_failed')]
    public function testFetchAboveTheLimitKeepsTheCloneAndRefusesFetches(): void
    {
        $this->setEnvironmentVariable('APP_PLAN_FREE_REPOSITORY_SIZE_MIB', '1');
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        self::enterNewTenant();
        $repositoryId = self::connect()->getId();
        self::runQueuedMessages();
        $source->commit(['random.bin' => random_bytes(2 * self::MIB)]);

        self::fetchRepository()->request($repositoryId);
        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame([SyncState::Idle, self::REPOSITORY_SIZE_ERROR], [$repository->getSyncState(), $repository->getLastError()]);
        self::assertGreaterThan(self::MIB, $repository->getCloneSizeBytes());
        self::assertDirectoryExists(self::clonePath($repository));

        try {
            self::fetchRepository()->fetchNow($repositoryId);
            self::fail('The fetch of a clone above the limit succeeded.');
        } catch (RepositoryUnavailable $unavailable) {
            self::assertSame(RepositoryUnavailableReason::FetchFailed, $unavailable->details()['reason']);
        }
    }
}
