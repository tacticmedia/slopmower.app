<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\LinkCommitsResult;
use App\Application\Query\RequestQuery;
use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\ReviewClosed;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\LinkCommits as LinkCommitsCommand;
use App\Domain\Request\CommitLinkState;
use App\Git\GitReader;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;

/**
 * Q7. A commit that the server clone has is pinned before the transaction (D26). A missing one stays pending and
 * queues a fetch, which {@see ResolvePendingCommitLinks} follows.
 */
final class LinkCommits
{
    /** Requests during the delay reuse the queued fetch, so this is the debounce per repository. */
    public const int FETCH_DELAY_MILLISECONDS = 5000;

    public function __construct(
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly CommitLinkRepository $links,
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly PinRefs $pins,
        private readonly TenantDataPaths $paths,
    ) {
    }

    /**
     * @param list<RequestId> $requestIds
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition
     */
    public function link(CommitSha $commitSha, array $requestIds, ?string $note, Actor $actor): LinkCommitsResult
    {
        $first = $requestIds[0] ?? throw InvalidInput::field('requestIds', 'Must not be empty.');
        $repository = ($this->changeRequests->findFresh($first) ?? throw new NotFound())->getReview()->getRepository();
        $gitDir = $this->paths->clonePath($repository->getTenantId(), $repository->getId());
        $commit = $this->reader->hasCommit($gitDir, $commitSha) ? $this->reader->commitInfo($gitDir, $commitSha) : null;

        if (null !== $commit) {
            $this->pins->pinCommit($gitDir, $commitSha);
        }

        try {
            $events = $this->commands->linkCommits(new LinkCommitsCommand($commitSha, $requestIds, $note), $actor, null === $commit ? [] : [$commit]);
        } catch (\Throwable $failure) {
            if (null !== $commit && !$this->links->isLinkedInRepository($repository->getId(), $commitSha)) {
                $this->pins->unpinCommit($gitDir, $commitSha);
            }

            throw $failure;
        }

        if (null === $commit) {
            $this->requestFetch($repository->getId());
        }

        return $this->requests->linkCommitsResult($commitSha, null === $commit ? CommitLinkState::Pending : CommitLinkState::Available, $requestIds, $events);
    }

    private function requestFetch(RepositoryId $repositoryId): void
    {
        try {
            $this->fetchRepository->request($repositoryId, self::FETCH_DELAY_MILLISECONDS);
        } catch (RepositoryUnavailable) {
            // The access is removed. The link stays pending, and Q10 answers repository_unavailable.
        }
    }
}
