<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\CodeAccess;
use App\Entity\Repository;
use App\Git\CodeReader;
use App\Git\GitCodeReader;
use App\Git\GitRunner;
use App\Git\ScratchCopies;
use App\Git\TenantDataPaths;
use App\GitHub\GitHubCodeReader;
use App\GitHub\GitHubRepositoryClient;
use App\GitHub\InstallationTokenProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The {@see CodeReader} of a repository by its code access (D111, D114). Keeps each reader, with its `cat-file` process and
 * scratch copies, until {@see reset()}: after each message, and at the end of each request, also after an exception.
 */
#[AsEventListener(KernelEvents::TERMINATE, method: 'reset')]
final class CodeReaders implements ResetInterface
{
    /** @var array<string, GitCodeReader> By git directory */
    private array $gitReaders = [];

    /** @var array<string, GitHubCodeReader> By repository ID */
    private array $gitHubReaders = [];

    public function __construct(
        private readonly GitRunner $git,
        private readonly TenantDataPaths $paths,
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly InstallationTokenProvider $tokens,
        #[Target('github_code.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly ScratchCopies $scratch,
        private readonly UsageLimits $usageLimits,
        #[Autowire(env: 'GITHUB_GIT_BASE_URL')]
        private readonly string $gitHubGitBaseUrl,
        private readonly ClockInterface $clock,
    ) {
    }

    public function of(Repository $repository): CodeReader
    {
        if (CodeAccess::Clone === $repository->getCodeAccess()) {
            return $this->git($this->paths->clonePath($repository->getTenantId(), $repository->getId()));
        }

        $tenantId = $repository->getTenantId();

        return $this->gitHubReaders[$repository->getId()->toString()] ??= new GitHubCodeReader(
            $repository->getId(),
            $repository->getName(),
            new GitHubRepositoryClient(
                $repository->getId(),
                $repository->getName(),
                $repository->getGithubInstallationId() ?? throw new \LogicException('A Pass-through repository has an installation ID.'),
                $repository->getGithubRepositoryId() ?? throw new \LogicException('A Pass-through repository has a GitHub ID.'),
                $this->githubClient,
                $this->tokens,
                $this->clock,
            ),
            $this->cache,
            $this->scratch,
            fn (int $sizeBytes) => $this->usageLimits->checkRepositorySize($tenantId, $sizeBytes),
            $this->gitHubGitBaseUrl,
            CodeAccess::Browser === $repository->getCodeAccess(),
        );
    }

    public function git(string $gitDir): GitCodeReader
    {
        return $this->gitReaders[$gitDir] ??= new GitCodeReader($this->git, $gitDir);
    }

    public function reset(): void
    {
        foreach ([...$this->gitReaders, ...$this->gitHubReaders] as $reader) {
            $reader->close();
        }

        $this->gitReaders = [];
        $this->gitHubReaders = [];
    }
}
