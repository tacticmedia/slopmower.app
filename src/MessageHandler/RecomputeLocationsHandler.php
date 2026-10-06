<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Anchoring\Locator;
use App\Application\FetchRepository;
use App\Domain\Anchor\Anchor;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Location\LocationStatus;
use App\Entity\ChangeRequest;
use App\Entity\Repository;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Message\RecomputeLocations;
use App\Repository\ChangeRequestRepository;
use App\Repository\ReviewEventRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Step 3 of docs/specs/webhooks-and-live-updates.md section 2.2: the `outdated` flag of each request of the review,
 * from its location at the default-branch head.
 */
#[AsMessageHandler]
final class RecomputeLocationsHandler
{
    private const array OUTDATED_STATUSES = [LocationStatus::Changed, LocationStatus::Orphaned];

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly ChangeRequestRepository $requests,
        private readonly ReviewEventRepository $events,
        private readonly FetchRepository $fetchRepository,
        private readonly Locator $locator,
        private readonly EntityManagerInterface $entityManager,
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecomputeLocations $job): void
    {
        $review = $this->reviews->findFresh($job->reviewId);

        if (null === $review) {
            return;
        }

        $repository = $review->getRepository();
        $head = $this->defaultBranchHead($repository);
        $openedEventIds = $this->events->findOpenedEventIds($review->getId());
        $changed = [];

        foreach ($this->requests->findFreshOfReview($review->getId()) as $request) {
            $openedEventId = $openedEventIds[$request->getId()->toString()] ?? null;
            $anchor = $request->getAnchor();

            // A general request is never located, so its flag stays null.
            if (null === $openedEventId || null === $anchor) {
                continue;
            }

            $outdated = null === $head ? null : $this->outdated($repository, $openedEventId, $request, $anchor, $head);

            if ($outdated !== $request->getOutdated()) {
                $request->recordOutdated($outdated);
                $changed[] = $request->getId();
            }
        }

        if ([] === $changed) {
            return;
        }

        // The flush commits first, so a client that reloads the review reads the published flags.
        $this->entityManager->flush();
        $this->publisher->publish($this->topics->review($review->getTenantId(), $review->getId()), [
            'type' => 'outdated',
            'reviewId' => $review->getId()->toString(),
            'requestIds' => array_map(static fn (RequestId $id): string => $id->toString(), $changed),
        ]);
    }

    /**
     * @return CommitSha|null Null when the clone is gone or the repository has no default branch
     */
    private function defaultBranchHead(Repository $repository): ?CommitSha
    {
        try {
            return $this->fetchRepository->cloneReady($repository->getId())->getDefaultBranchHead();
        } catch (RepositoryUnavailable) {
            return null;
        }
    }

    /**
     * A failure keeps the flag, so one request that git cannot locate does not stop the others.
     */
    private function outdated(Repository $repository, EventId $openedEventId, ChangeRequest $request, Anchor $anchor, CommitSha $head): ?bool
    {
        try {
            return in_array($this->locator->locate($repository, $openedEventId, $anchor, $head)->status, self::OUTDATED_STATUSES, true);
        } catch (\Throwable $failure) {
            $this->logger->error('The location of the request {requestId} failed.', ['requestId' => $request->getId()->toString(), 'exception' => $failure]);

            return $request->getOutdated();
        }
    }
}
