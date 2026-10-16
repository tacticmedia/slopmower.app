<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\RepositoryAccess;
use App\Domain\Request\PullRequestState;
use App\Message\ReadPullRequestJob;
use App\Repository\ChangeRequestRepository;
use App\Repository\PullRequestRepository;
use App\Repository\RepositoryRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the jobs that a suspension or a missing permission of an installation skipped, for the pull requests that the
 * requests of the tenant link in the `active` repositories of the installation. Call it after the commit.
 */
final class QueuePullRequestRefresh
{
    public function __construct(
        private readonly RepositoryRepository $repositories,
        private readonly ChangeRequestRepository $requests,
        private readonly PullRequestRepository $pullRequests,
        private readonly QueueCheckRun $queueCheckRun,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Without $readEvery, reads only the pull requests without a read state. A read that sets a new head SHA queues its
     * check run, see {@see \App\MessageHandler\PullRequestStateHandler::record()}.
     */
    public function ofInstallation(int $installationId, bool $readEvery, bool $checkRuns): void
    {
        foreach ($this->repositories->findBy(['githubInstallationId' => $installationId, 'access' => RepositoryAccess::Active]) as $repository) {
            $numbers = $this->requests->findLinkedPullRequestNumbers($repository->getId());
            $records = $this->pullRequests->findOfRepository($repository->getId(), $numbers);

            foreach ($numbers as $number) {
                $record = $records[$number] ?? null;

                if ($readEvery || null === $record || PullRequestState::Unknown === $record->shownState()) {
                    $this->bus->dispatch(new ReadPullRequestJob($repository->getId(), $number));
                }

                if ($checkRuns && null !== $record) {
                    $this->queueCheckRun->request($record->getId());
                }
            }
        }
    }
}
