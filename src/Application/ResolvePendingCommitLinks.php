<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryFetched;
use App\Domain\Actor\Actor;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Git\CommitInfo;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\EventStore\CommandExecutor;
use App\Git\GitReader;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Repository\CommitLinkRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Step 2 of docs/specs/webhooks-and-live-updates.md section 2.2. The only writer of commit.found.
 */
final class ResolvePendingCommitLinks
{
    public function __construct(
        private readonly CommitLinkRepository $links,
        private readonly CommandExecutor $executor,
        private readonly GitReader $reader,
        private readonly PinRefs $pins,
        private readonly TenantDataPaths $paths,
    ) {
    }

    #[AsEventListener]
    public function onRepositoryFetched(RepositoryFetched $event): void
    {
        $gitDir = $this->paths->clonePath($event->tenantId, $event->repositoryId);
        /** @var array<string, array{commit: CommitInfo, reviewIds: array<string, ReviewId>}> $found By SHA */
        $found = [];
        /** @var array<string, true> $missing SHAs that the clone does not have */
        $missing = [];

        foreach ($this->links->findFreshPendingOfRepository($event->repositoryId) as $link) {
            $sha = $link->getCommitSha();

            if (isset($missing[$sha->toString()])) {
                continue;
            }

            if (!isset($found[$sha->toString()])) {
                if (!$this->reader->hasCommit($gitDir, $sha)) {
                    $missing[$sha->toString()] = true;

                    continue;
                }

                $found[$sha->toString()] = ['commit' => $this->reader->commitInfo($gitDir, $sha), 'reviewIds' => []];
            }

            $reviewId = $link->getRequest()->getReview()->getId();
            $found[$sha->toString()]['reviewIds'][$reviewId->toString()] = $reviewId;
        }

        foreach ($found as ['commit' => $commit, 'reviewIds' => $reviewIds]) {
            $this->pins->pinCommit($gitDir, $commit->commitSha);

            foreach ($reviewIds as $reviewId) {
                $this->recordFound($event->repositoryId, $reviewId, $commit);
            }
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
