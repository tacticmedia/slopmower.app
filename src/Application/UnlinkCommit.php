<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\NotFound;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\UnlinkCommit as UnlinkCommitCommand;
use App\Git\GitFailed;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;
use Psr\Log\LoggerInterface;

/**
 * Q8. Deletes the pin of the commit after the commit of the transaction, when no other link in the repository names it.
 */
final class UnlinkCommit
{
    public function __construct(
        private readonly RequestCommands $commands,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly CommitLinkRepository $links,
        private readonly PinRefs $pins,
        private readonly TenantDataPaths $paths,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RecordedEvent>
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition
     */
    public function unlink(RequestId $requestId, CommitSha $commitSha, Actor $actor): array
    {
        $events = $this->commands->unlinkCommit(new UnlinkCommitCommand($requestId, $commitSha), $actor);
        $repository = ($this->changeRequests->findFresh($requestId) ?? throw new NotFound())->getReview()->getRepository();

        if (!$this->links->isLinkedInRepository($repository->getId(), $commitSha)) {
            try {
                $this->pins->unpinCommit($this->paths->clonePath($repository->getTenantId(), $repository->getId()), $commitSha);
            } catch (GitFailed $failure) {
                // The unlink is committed. A removed repository has no clone left.
                $this->logger->warning('The pin of commit {commitSha} could not be deleted.', ['commitSha' => $commitSha->toString(), 'exception' => $failure]);
            }
        }

        return $events;
    }
}
