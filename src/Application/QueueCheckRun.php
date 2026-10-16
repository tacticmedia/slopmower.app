<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\EventType;
use App\Domain\Event\PullRequestLinkedEvent;
use App\Domain\Event\PullRequestUnlinkedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\PullRequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\PullRequestUrl;
use App\Entity\ChangeRequest;
use App\Entity\PullRequest;
use App\EventStore\EventsCommitted;
use App\Message\CheckRunJob;
use App\Repository\ChangeRequestRepository;
use App\Repository\PullRequestRepository;
use App\Repository\ReviewRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues {@see CheckRunJob}: the triggers of docs/specs/webhooks-and-live-updates.md, "Check run".
 */
final class QueueCheckRun
{
    /** Triggers during the delay reuse the queued job, so this is the debounce per pull request. */
    public const int DELAY_MILLISECONDS = 5000;

    private const array REQUEST_TRIGGERS = [EventType::RequestReadyForReview, EventType::RequestVerified, EventType::RequestReopened, EventType::RequestWontDo, EventType::RequestRetitled];

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly ChangeRequestRepository $requests,
        private readonly PullRequestRepository $pullRequests,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Records exist only for GitHub repositories, so a local repository queues nothing.
     */
    #[AsEventListener]
    public function onEventsCommitted(EventsCommitted $committed): void
    {
        foreach ($this->records($committed->reviewId, array_merge(...array_map($this->linkedPullRequests(...), $committed->events))) as $id) {
            $this->request($id);
        }
    }

    /**
     * Read before a deletion of the review, whose jobs the caller queues after the commit.
     *
     * @return list<PullRequestId> The records of the pull requests that the requests of the review link
     */
    public function linkedByReview(ReviewId $reviewId): array
    {
        return $this->records($reviewId, $this->linkedByRequestsOf($reviewId));
    }

    /**
     * A trigger while the job is queued queues nothing. The job clears the flag first, so a trigger during its run
     * queues one more.
     */
    public function request(PullRequestId $id): void
    {
        if (!$this->pullRequests->requestCheckRun($id)) {
            return;
        }

        try {
            $this->bus->dispatch(new CheckRunJob($id), [new DelayStamp(self::DELAY_MILLISECONDS)]);
        } catch (\Throwable $failure) {
            $this->pullRequests->clearCheckRunRequest($id);

            throw $failure;
        }
    }

    /**
     * @return list<PullRequestUrl> The pull requests whose check run the event changes
     */
    private function linkedPullRequests(RecordedEvent $recorded): array
    {
        $event = $recorded->event;

        return match (true) {
            $event instanceof PullRequestLinkedEvent, $event instanceof PullRequestUnlinkedEvent => [$event->url],
            EventType::ReviewClosed === $recorded->type => $this->linkedByRequestsOf($recorded->reviewId),
            \in_array($recorded->type, self::REQUEST_TRIGGERS, true) && null !== $recorded->requestId => $this->requests->findFresh($recorded->requestId)?->getPullRequestUrls() ?? [],
            default => [],
        };
    }

    /**
     * @return list<PullRequestUrl>
     */
    private function linkedByRequestsOf(ReviewId $reviewId): array
    {
        return array_merge(...array_map(static fn (ChangeRequest $request): array => $request->getPullRequestUrls(), $this->requests->findFreshOfReview($reviewId)));
    }

    /**
     * @param list<PullRequestUrl> $urls
     *
     * @return list<PullRequestId> The records of the GitHub pull requests of the URLs in the repository of the review
     */
    private function records(ReviewId $reviewId, array $urls): array
    {
        $numbers = array_values(array_unique(array_filter(array_map(static fn (PullRequestUrl $url): ?int => $url->gitHubNumber(), $urls), static fn (?int $number): bool => null !== $number)));
        $review = [] === $numbers ? null : $this->reviews->find($reviewId);

        return null === $review ? [] : array_values(array_map(
            static fn (PullRequest $pullRequest): PullRequestId => $pullRequest->getId(),
            $this->pullRequests->findOfRepository($review->getRepository()->getId(), $numbers),
        ));
    }
}
