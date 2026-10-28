<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\FetchRepository;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\SyncState;
use App\Git\TenantDataPaths;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D88. The clone of {@see ClonedGitHubRepositories} passes the check with the permission `write`.
 */
final class GitHubAccessCheckTest extends KernelTestCase
{
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    /**
     * @return array<string, array{string}>
     */
    public static function loginsWithoutAccess(): array
    {
        return [
            'the permission none' => [GitHubResponses::FORMER_MEMBER_LOGIN],
            'an unknown login' => [GitHubResponses::UNKNOWN_LOGIN],
        ];
    }

    #[DataProvider('loginsWithoutAccess')]
    #[TestDox('A fetch for $_dataName fails without the new commit, and keeps the clone and the access')]
    public function testFetchWithoutAccessFails(string $login): void
    {
        $this->useLocalGitHub();
        $source = $this->gitHubSource();
        $repository = self::clonedGitHubRepository(1);
        $head = self::storedRepository($repository)->getDefaultBranchHead();
        $source->commit(['CHANGELOG.md' => "Fixed\n"]);
        $source->git('push', '--quiet', 'origin', 'main');
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE app_user SET login = ? WHERE tenant_id = ?', [$login, $repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());

        self::getContainer()->get(FetchRepository::class)->request($repository->getId());
        self::runQueuedMessages();

        $stored = self::storedRepository($repository);
        self::assertSame('The GitHub user has no access to this repository', $stored->getLastError());
        self::assertEquals($head, $stored->getDefaultBranchHead());
        self::assertSame(SyncState::Idle, $stored->getSyncState());
        self::assertSame(RepositoryAccess::Active, $stored->getAccess());
        self::assertDirectoryExists(self::getContainer()->get(TenantDataPaths::class)->clonePath($repository->getTenantId(), $repository->getId()));
    }
}
