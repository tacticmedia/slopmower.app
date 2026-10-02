<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Actor\Actor;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\MarkReadyForReview as MarkReadyForReviewCommand;
use App\Repository\ChangeRequestRepository;

/**
 * Q10. Pending links get one synchronous fetch, during which {@see ResolvePendingCommitLinks} writes commit.found in
 * its own transaction. The command then reads the links again.
 */
final class MarkReadyForReview
{
    public function __construct(
        private readonly RequestCommands $commands,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly FetchRepository $fetchRepository,
    ) {
    }

    /**
     * @return list<RecordedEvent>
     *
     * @throws NotFound|InvalidInput|ReviewClosed|InvalidTransition|CommitsNotAvailable|RepositoryUnavailable
     */
    public function markReady(RequestId $requestId, string $note, Actor $actor): array
    {
        $command = new MarkReadyForReviewCommand($requestId, $note);

        try {
            // The decider checks pending links last, so every other refusal comes before any fetch.
            return $this->commands->markReadyForReview($command, $actor);
        } catch (CommitsNotAvailable) {
            $request = $this->changeRequests->findFresh($requestId) ?? throw new NotFound();
            $this->fetchRepository->fetchNow($request->getReview()->getRepository()->getId());
        }

        return $this->commands->markReadyForReview($command, $actor);
    }
}
