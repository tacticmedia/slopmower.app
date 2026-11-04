<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryFetched;
use App\Domain\Actor\Actor;
use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\CodeAccess;
use App\EventStore\CommandExecutor;
use App\Git\CodeReader;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Repository\CommitLinkRepository;
use App\Repository\RepositoryRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Step 3 of docs/specs/webhooks-and-live-updates.md section 2.2. The only writer of commit.found. A Server copy pins
 * each found commit.
 */
final class ResolvePendingCommitLinks
{
    public function __construct(
        private readonly CommitLinkRepository $links,
        private readonly CommandExecutor $executor,
        private readonly RepositoryRepository $repositories,
        private readonly CodeReaders $readers,
        private readonly PinRefs $pins,
        private readonly TenantDataPaths $paths,
    ) {
    }

    #[AsEventListener]
    public function onRepositoryFetched(RepositoryFetched $event): void
    {
        $repository = $this->repositories->findFresh($event->repositoryId);

        if (null === $repository) {
            return;
        }

        $reader = $this->readers->of($repository);
        $gitDir = CodeAccess::Clone === $repository->getCodeAccess() ? $this->paths->clonePath($event->tenantId, $event->repositoryId) : null;
        /** @var array<string, array{commit: CommitInfo, reviewIds: array<string, ReviewId>}> $found By SHA */
        $found = [];
        /** @var array<string, true> $missing SHAs that the repository does not have */
        $missing = [];

        foreach ($this->links->findFreshPendingOfRepository($event->repositoryId) as $link) {
            $sha = $link->getCommitSha();

            if (isset($missing[$sha->toString()])) {
                continue;
            }

            if (!isset($found[$sha->toString()])) {
                $commit = self::availableCommit($reader, $sha);

                if (null === $commit) {
                    $missing[$sha->toString()] = true;

                    continue;
                }

                $found[$sha->toString()] = ['commit' => $commit, 'reviewIds' => []];
            }

            $reviewId = $link->getRequest()->getReview()->getId();
            $found[$sha->toString()]['reviewIds'][$reviewId->toString()] = $reviewId;
        }

        foreach ($found as ['commit' => $commit, 'reviewIds' => $reviewIds]) {
            if (null !== $gitDir) {
                $this->pins->pinCommit($gitDir, $commit->commitSha);
            }

            foreach ($reviewIds as $reviewId) {
                $this->recordFound($event->repositoryId, $reviewId, $commit);
            }
        }
    }

    /**
     * A read that fails keeps the link pending, so the listeners after this one still run.
     */
    private static function availableCommit(CodeReader $reader, CommitSha $sha): ?CommitInfo
    {
        try {
            return $reader->hasCommit($sha) ? $reader->commitInfo($sha) : null;
        } catch (RepositoryUnavailable|RateLimited) {
            return null;
        }
    }

    /**
     * Reads the pending links again under the tenant lock, so a concurrent resolution writes no second event.
     */
    private function recordFound(RepositoryId $repositoryId, ReviewId $reviewId, CommitInfo $commit): void
    {
        $this->executor->execute($reviewId, Actor::system(), function () use ($repositoryId, $reviewId, $commit): array {
            $requestIds = [];

            foreach ($this->links->findFreshPendingOfRepository($repositoryId) as $link) {
                $request = $link->getRequest();

                if ($link->getCommitSha()->equals($commit->commitSha) && $request->getReview()->getId()->equals($reviewId)) {
                    $requestIds[] = $request->getId();
                }
            }

            return [] === $requestIds ? [] : [new DecidedEvent(new CommitFoundEvent($commit->commitSha, $requestIds, $commit->subject, $commit->committedAt))];
        });
    }
}
