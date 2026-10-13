<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryChanged;
use App\Application\Event\RepositoryFetched;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositorySource;
use App\Entity\Repository;
use App\Git\BareClone;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Git\RepositoryLock;
use App\Git\TenantDataPaths;
use App\GitHub\GitHubRequestFailed;
use App\GitHub\InstallationTokenProvider;
use App\Repository\CommitLinkRepository;
use App\Repository\GitHubInstallationRepository;
use App\Repository\RepositoryRepository;
use App\Source\FetchCommand;
use App\Source\LocalRepositoryDiscovery;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Clones or fetches the server clone from the source of its repository (D24). The caller holds the
 * {@see RepositoryLock} for {@see fetch()} and calls {@see synced()} after it releases the lock.
 */
final class ServerClone
{
    private const string GITHUB_SUSPENDED = 'GitHub App installation suspended';

    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly CommitLinkRepository $links,
        private readonly BareClone $bareClone,
        private readonly GitReader $reader,
        private readonly LocalRepositoryDiscovery $discovery,
        private readonly GitHubInstallationRepository $installations,
        private readonly InstallationTokenProvider $installationTokens,
        #[Autowire(env: 'GITHUB_GIT_BASE_URL')]
        private readonly string $gitHubGitBaseUrl,
        private readonly TenantDataPaths $paths,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return RepositoryFetched|null Null after a failure, which the row records in `lastError`
     */
    public function fetch(Repository $repository, bool $clone, float $timeoutSeconds): ?RepositoryFetched
    {
        $id = $repository->getId();
        $gitDir = $this->paths->clonePath($repository->getTenantId(), $id);
        $serverPaths = [$gitDir];
        $filesystem = new Filesystem();

        try {
            if (RepositorySource::Github === $repository->getSource()) {
                $command = $this->gitHubFetchCommand($repository);
            } else {
                $sourcePath = $this->localSourcePath($repository);
                $serverPaths[] = $sourcePath;
                $command = FetchCommand::localDirectory($sourcePath);
            }

            if ($clone) {
                // A failed earlier attempt may have left a partial clone.
                $filesystem->remove($gitDir);
                $this->bareClone->init($gitDir);
            }

            $this->bareClone->fetch($gitDir, $command->url, $this->pendingShas($id), $timeoutSeconds, $command->secretConfiguration, $command->uploadPack);
        } catch (GitFailed|SourceUnavailable|GitHubRequestFailed|IOExceptionInterface $failure) {
            $this->logger->warning('The sync of repository {repositoryId} failed.', ['repositoryId' => $id->toString(), 'exception' => $failure]);
            // Recorded first, so a failing removal cannot leave the row in `cloning`.
            $this->repositories->recordFailure($id, self::lastError($failure, $serverPaths));

            if ($clone) {
                $filesystem->remove($gitDir);
            }

            return null;
        }

        $defaultBranch = $this->reader->headBranch($gitDir);

        try {
            $head = null === $defaultBranch ? null : $this->reader->resolveCommit($gitDir, RefName::fromString("refs/heads/{$defaultBranch}"));
        } catch (CommitNotFound|InvalidInput) {
            // Git accepts branch names that RefName refuses, for example names longer than 255 characters.
            $head = null;
        }

        // An unborn branch is not a default branch yet.
        $this->repositories->recordSuccess($id, $this->clock->now(), null === $head ? null : $defaultBranch, $head);

        return new RepositoryFetched($repository->getTenantId(), $id, $repository->getDefaultBranchHead(), $head);
    }

    /**
     * The `repository` update follows the `events` updates of the {@see RepositoryFetched} listeners,
     * docs/specs/webhooks-and-live-updates.md section 2.2.
     *
     * @param RepositoryFetched|null $fetched Null after a failure
     */
    public function synced(Repository $repository, ?RepositoryFetched $fetched): void
    {
        try {
            if (null !== $fetched) {
                $this->dispatcher->dispatch($fetched);
            }
        } finally {
            $this->dispatcher->dispatch(new RepositoryChanged($repository->getTenantId(), $repository->getId()));
        }
    }

    /**
     * @return list<CommitSha> The commits of the pending links, which {@see ResolvePendingCommitLinks} resolves
     */
    private function pendingShas(RepositoryId $id): array
    {
        $shas = [];

        foreach ($this->links->findFreshPendingOfRepository($id) as $link) {
            $shas[$link->getCommitSha()->toString()] = $link->getCommitSha();
        }

        return array_values($shas);
    }

    /**
     * The first line of the message, without git arguments and server paths. The log holds the full message.
     *
     * @param list<string> $serverPaths
     */
    private static function lastError(GitFailed|SourceUnavailable|GitHubRequestFailed|IOExceptionInterface $failure, array $serverPaths): string
    {
        $message = str_replace($serverPaths, GitRunner::REDACTED, $failure instanceof GitFailed ? $failure->summary : $failure->getMessage());

        return trim(explode("\n", $message, 2)[0]);
    }

    /**
     * @throws SourceUnavailable
     */
    private function localSourcePath(Repository $repository): string
    {
        $directory = (string) $repository->getLocalDirectory();
        $candidate = $this->discovery->find($directory)
            ?? throw new SourceUnavailable("The directory {$directory} is not a repository under the local repositories directory, or local repositories are off.");

        return $candidate->realPath;
    }

    /**
     * The command carries a new installation token, which lives only as long as the command.
     *
     * @throws SourceUnavailable   While the installation is suspended
     * @throws GitHubRequestFailed
     */
    private function gitHubFetchCommand(Repository $repository): FetchCommand
    {
        $installationId = $repository->getGithubInstallationId() ?? throw new \LogicException('A GitHub repository has an installation ID.');

        if ($this->installations->isSuspended($installationId)) {
            throw new SourceUnavailable(self::GITHUB_SUSPENDED);
        }

        $token = $this->installationTokens->forRepository($installationId, (int) $repository->getGithubRepositoryId(), InstallationTokenProvider::CONTENTS_READ);

        return FetchCommand::gitHub($this->gitHubGitBaseUrl, $repository->getName(), $token);
    }
}
