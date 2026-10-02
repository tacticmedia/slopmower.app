<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Repository\SyncState;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CloneFailureTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    #[TestDox('A source that stops being a repository before the clone sets failed with lastError')]
    public function testBrokenSourceSetsFailed(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        self::enterNewTenant();
        $repositoryId = self::connect()->getId();
        // An empty .git directory still makes a candidate, so git itself fails, after the clone directory exists.
        $filesystem = new Filesystem();
        $filesystem->remove("{$source->path}/.git");
        $filesystem->mkdir("{$source->path}/.git");

        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame(SyncState::Failed, $repository->getSyncState());
        self::assertStringContainsString('does not appear to be a git repository', (string) $repository->getLastError());
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
    }

    #[TestDox('A clone directory that cannot be created sets failed with lastError')]
    public function testUncreatableCloneDirectorySetsFailed(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $tenantId = self::enterNewTenant();
        $repositoryId = self::connect()->getId();
        // A file where the tenant directory belongs. The container runs as root, so permissions cannot cause it.
        new Filesystem()->dumpFile("{$this->tenantDataDirectory}/{$tenantId}", '');

        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame(SyncState::Failed, $repository->getSyncState());
        self::assertStringContainsString('Failed to create', (string) $repository->getLastError());
    }
}
