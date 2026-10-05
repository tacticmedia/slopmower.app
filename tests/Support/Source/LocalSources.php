<?php

declare(strict_types=1);

namespace App\Tests\Support\Source;

use App\Application\FetchRepository;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use App\Git\GitRunner;
use App\Git\TenantDataPaths;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Git\GitRepositoryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;

/**
 * Local sources and server clones in temporary directories, for KernelTestCase tests that use EnvironmentVariables and
 * TemporaryDirectories. {@see useTemporaryLocalSources()} runs before the kernel boots.
 */
trait LocalSources
{
    private string $localRepositoriesDirectory;

    private string $tenantDataDirectory;

    private function useTemporaryLocalSources(?string $localRepositoriesDirectory = null): void
    {
        $this->localRepositoriesDirectory = $localRepositoriesDirectory ?? $this->temporaryDirectory();
        $this->tenantDataDirectory = $this->temporaryDirectory();
        $this->setEnvironmentVariable('APP_LOCAL_REPOSITORIES_DIR', $this->localRepositoriesDirectory);
        $this->setEnvironmentVariable('APP_TENANT_DATA_DIR', $this->tenantDataDirectory);
    }

    /**
     * A repository with one commit on main.
     */
    private function newSource(string $directory = 'billing'): GitRepositoryBuilder
    {
        $source = GitRepositoryBuilder::init("{$this->localRepositoriesDirectory}/{$directory}");
        $source->commit(['README.md' => "Hello\n"]);

        return $source;
    }

    /**
     * A `cloning` repository row of the current tenant with its clone job queued, as R3 leaves it.
     */
    private static function connect(string $directory = 'billing'): Repository
    {
        $container = self::getContainer();
        $repository = Repository::local($container->get(TenantContext::class)->get(), RepositoryName::local($directory), $directory, Clock::get()->now());
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($repository);
        $entityManager->flush();
        self::fetchRepository()->queueClone($repository);

        return $repository;
    }

    private static function fetchRepository(): FetchRepository
    {
        return self::getContainer()->get(FetchRepository::class);
    }

    private static function freshRepository(RepositoryId $id): Repository
    {
        return self::getContainer()->get(RepositoryRepository::class)->findFresh($id) ?? self::fail("The repository {$id} does not exist.");
    }

    private static function clonePath(Repository $repository): string
    {
        return self::getContainer()->get(TenantDataPaths::class)->clonePath($repository->getTenantId(), $repository->getId());
    }

    private static function cloneGit(Repository $repository, string ...$arguments): string
    {
        return new GitRunner(new NullLogger())->run(self::clonePath($repository), array_values($arguments));
    }
}
