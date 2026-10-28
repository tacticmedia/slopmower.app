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
use Doctrine\DBAL\Connection;
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
        self::assertStringStartsWith('Failed to create "[redacted]": ', (string) $repository->getLastError());
    }

    #[TestDox('An unexpected exception during a clone sets failed, removes the partial clone, and fails the job')]
    public function testUnexpectedExceptionSetsFailed(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        self::enterNewTenant();
        $repositoryId = self::connect()->getId();
        $connection = self::getContainer()->get(Connection::class);
        // A database error after the clone directory exists. The failure record sets clone_size_bytes to NULL.
        $connection->executeStatement(<<<'SQL'
            CREATE FUNCTION test_refuse_clone_size() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'The database refuses the clone size.'; END $$;
            CREATE TRIGGER test_refuse_clone_size BEFORE UPDATE OF clone_size_bytes ON repository FOR EACH ROW WHEN (NEW.clone_size_bytes IS NOT NULL) EXECUTE FUNCTION test_refuse_clone_size();
            SQL);

        try {
            self::runQueuedMessages();
            self::fail('The clone job did not fail.');
        } catch (\Throwable $failure) {
            self::assertStringContainsString('The database refuses the clone size.', self::messages($failure));
        } finally {
            $connection->executeStatement('DROP TRIGGER test_refuse_clone_size ON repository; DROP FUNCTION test_refuse_clone_size()');
        }

        $repository = self::freshRepository($repositoryId);
        self::assertSame([SyncState::Failed, 'The sync failed.'], [$repository->getSyncState(), $repository->getLastError()]);
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
    }

    private static function messages(\Throwable $failure): string
    {
        $messages = [];

        for (; null !== $failure; $failure = $failure->getPrevious()) {
            $messages[] = $failure->getMessage();
        }

        return implode("\n", $messages);
    }
}
