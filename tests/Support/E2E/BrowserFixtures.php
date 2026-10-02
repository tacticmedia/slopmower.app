<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use App\Application\ConnectLocalRepository;
use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Entity\Repository;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Git\FixtureRepositories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Panther\Client;

/**
 * Users, local sources, repositories, and reviews for PantherTestCase tests that use ResetDatabase. The PHPUnit
 * process writes the database and the directories that the e2e site reads: the `.env.test` and `.env.e2e` values of
 * APP_LOCAL_REPOSITORIES_DIR and APP_TENANT_DATA_DIR are equal.
 */
trait BrowserFixtures
{
    use QueuedMessages;

    private const string OVERRIDE_MARKER = '# Written by BrowserFixtures::disableLocalRepositories()';

    /** @var list<string> */
    private array $pathsToRemove = [];

    /**
     * The user that `/_e2e/sign-in/{login}` signs in.
     */
    private function user(string $login = 'jan'): User
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin($login)->create();
        $this->pathsToRemove[] = Path::join(self::environmentPath('APP_TENANT_DATA_DIR'), $user->getTenantId()->toString());

        return $user;
    }

    private static function browserSignedInAs(User $user): Client
    {
        $client = static::createPantherClient();
        $client->request('GET', '/_e2e/sign-in/'.$user->getLogin());

        return $client;
    }

    /**
     * A git repository with one commit on main.
     *
     * @param array<string, string> $files Content by path, for example {@see FixtureRepositories::codeView()}
     *
     * @return string The directory name
     */
    private function localSource(array $files = ['README.md' => "Hello\n"]): string
    {
        $directory = 'e2e-'.bin2hex(random_bytes(4));
        $path = Path::join(self::environmentPath('APP_LOCAL_REPOSITORIES_DIR'), $directory);
        $this->pathsToRemove[] = $path;
        GitRepositoryBuilder::init($path)->commit($files);

        return $directory;
    }

    /**
     * Connects the source with R3's service and runs the clone job.
     */
    private static function connectedRepository(User $user, string $directory): Repository
    {
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        $repository = self::getContainer()->get(ConnectLocalRepository::class)->connect($directory)->repository;
        self::runQueuedMessages();

        return $repository;
    }

    private static function startedReview(User $user, Repository $repository, string $title): ReviewId
    {
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());

        return self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), RefName::fromString('main'), $title, Actor::user($user->getId()));
    }

    /**
     * Turns local repositories off on the e2e site, which loads the .env files on every request.
     */
    private function disableLocalRepositories(): void
    {
        $this->pathsToRemove[] = self::environmentOverridePath();
        new Filesystem()->dumpFile(self::environmentOverridePath(), self::OVERRIDE_MARKER."\nAPP_LOCAL_REPOSITORIES_DIR=\n");
    }

    /**
     * A killed run leaves its override behind, and every later test would see local repositories off.
     */
    #[Before]
    protected function removeLeftoverEnvironmentOverride(): void
    {
        $path = self::environmentOverridePath();

        if (!is_file($path)) {
            return;
        }

        if (!str_contains((string) file_get_contents($path), self::OVERRIDE_MARKER)) {
            self::fail("{$path} changes the e2e site, and BrowserFixtures did not write it. Remove it, then run the E2E suite again.");
        }

        new Filesystem()->remove($path);
    }

    #[After]
    protected function removeBrowserFixturePaths(): void
    {
        new Filesystem()->remove($this->pathsToRemove);
        $this->pathsToRemove = [];
    }

    private static function environmentOverridePath(): string
    {
        return dirname(__DIR__, 3).'/.env.e2e.local';
    }

    private static function environmentPath(string $name): string
    {
        $path = $_SERVER[$name] ?? null;
        self::assertIsString($path, "The test environment has no {$name}.");

        return Path::makeAbsolute($path, dirname(__DIR__, 3));
    }
}
