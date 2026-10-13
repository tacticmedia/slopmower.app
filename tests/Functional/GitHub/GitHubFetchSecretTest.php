<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\FetchRepository;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Git\TenantDataPaths;
use App\Repository\RepositoryRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class GitHubFetchSecretTest extends KernelTestCase
{
    use EnvironmentVariables;
    use GitHubRows;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('The token is not in the git configuration, the argv, exception messages, or logs')]
    public function testTokenLeaksNowhere(): void
    {
        $this->useLocalGitHub();
        $testLog = self::getContainer()->getParameter('kernel.logs_dir').'/test.log';
        $testLogOffset = is_file($testLog) ? (int) filesize($testLog) : 0;
        $gitTrace = $this->temporaryDirectory().'/git-trace.log';
        // Git writes the argv of each git process, its subprocesses included, to this file.
        $this->setEnvironmentVariable('GIT_TRACE', $gitTrace);
        $this->gitHubSource('acme/billing');
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeInstallation($tenantId);
        self::storeCandidate($tenantId);
        self::storeCandidate($tenantId, 812346, 'acme/missing');
        $cloned = self::connectGitHub($tenantId);
        $failed = self::connectGitHub($tenantId, 812346);

        self::runQueuedMessages();

        self::assertSame(SyncState::Idle, self::fresh($cloned)->getSyncState());
        $failure = self::fresh($failed);
        self::assertSame(SyncState::Failed, $failure->getSyncState());
        self::assertStringStartsWith('git fetch exited with 128: fatal:', (string) $failure->getLastError());
        $records = self::getContainer()->get('monolog.handler.records');
        // The formatted record holds the context, with the messages of the exceptions and their previous exceptions.
        $logs = implode("\n", array_map(static fn (LogRecord $record): string => (string) $record->formatted, $records->getRecords()));
        self::assertStringContainsString('git fetch --no-auto-maintenance --prune --end-of-options file://', $logs, 'The logs hold the exception with the argv.');
        self::assertStringContainsString('git fetch', (string) file_get_contents($gitTrace));

        $leaks = ['token' => GitHubResponses::INSTALLATION_TOKEN, 'header' => base64_encode('x-access-token:'.GitHubResponses::INSTALLATION_TOKEN)];
        $places = [
            'git configuration' => (string) file_get_contents(self::clonePath($cloned).'/config'),
            'argv' => (string) file_get_contents($gitTrace),
            'lastError' => (string) $failure->getLastError(),
            'logs and exception messages' => $logs,
            'test log' => (string) file_get_contents($testLog, offset: $testLogOffset),
        ];

        foreach ($places as $place => $content) {
            foreach ($leaks as $form => $leak) {
                self::assertStringNotContainsString($leak, $content, "The {$form} is in the {$place}.");
            }
        }

        self::assertSame('', self::filesContaining([$this->tenantDataDirectory, $this->gitHubDirectory], array_values($leaks)), 'These files hold the token.');
    }

    #[TestDox('A fetch through the base-URL override from a local bare repository works')]
    public function testFetchFromLocalBareRepository(): void
    {
        $this->useLocalGitHub();
        $source = $this->gitHubSource('acme/billing');
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeInstallation($tenantId);
        self::storeCandidate($tenantId);
        $repository = self::connectGitHub($tenantId);
        self::runQueuedMessages();
        $head = $source->commit(['CHANGELOG.md' => "Second\n"]);
        $source->git('push', '--quiet', 'origin', 'main');

        self::getContainer()->get(FetchRepository::class)->request($repository->getId());
        self::runQueuedMessages();

        $fetched = self::fresh($repository);
        self::assertSame(SyncState::Idle, $fetched->getSyncState());
        self::assertSame('main', $fetched->getDefaultBranch());
        self::assertSame($head, (string) $fetched->getDefaultBranchHead());
    }

    private static function fresh(Repository $repository): Repository
    {
        return self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId()) ?? self::fail('The repository is gone.');
    }

    private static function clonePath(Repository $repository): string
    {
        return self::getContainer()->get(TenantDataPaths::class)->clonePath($repository->getTenantId(), $repository->getId());
    }

    /**
     * @param list<string> $directories
     * @param list<string> $needles
     *
     * @return string The paths of the files that contain a needle
     */
    private static function filesContaining(array $directories, array $needles): string
    {
        $paths = [];

        foreach (new Finder()->files()->ignoreDotFiles(false)->in($directories) as $file) {
            $content = $file->getContents();

            if (array_any($needles, static fn (string $needle): bool => str_contains($content, $needle))) {
                $paths[] = $file->getPathname();
            }
        }

        return implode("\n", $paths);
    }
}
