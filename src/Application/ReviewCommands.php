<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Actor\Actor;
use App\Domain\Error\NotFound;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\StartReview;
use App\Domain\Review\ReviewDecider;
use App\Entity\Repository;
use App\Entity\Review;
use App\EventStore\CommandExecutor;
use App\Repository\RepositoryRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Uid\Ulid;

#[Autoconfigure(public: true)]
final class ReviewCommands
{
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositories,
        private readonly ReviewRepository $reviews,
    ) {
    }

    /**
     * Creates the review row in the transaction of review.started, because the repository is not event data.
     *
     * @param ReviewId $reviewId Chosen by the caller, who writes the review pin before the transaction
     *
     * @return list<RecordedEvent>
     */
    public function start(ReviewId $reviewId, RepositoryId $repositoryId, StartReview $command, Actor $actor): array
    {
        return $this->executor->execute(
            $reviewId,
            $actor,
            function () use ($reviewId, $repositoryId, $command, $actor): array {
                $this->repository($repositoryId);

                return ReviewDecider::decide($command, $this->reviews->findFresh($reviewId)?->toSnapshot(), $actor);
            },
            function (array $recorded) use ($reviewId, $repositoryId, $command, $actor): void {
                $started = $recorded[0] ?? throw new \LogicException('review.started is missing.');
                $this->entityManager->persist(new Review($reviewId, $this->repository($repositoryId), $command->title, $command->ref, $command->commitSha, $actor, $started->occurredAt, $started->seq));
            },
        );
    }

    /**
     * @return list<RecordedEvent>
     */
    public function close(ReviewId $reviewId, CloseReview $command, Actor $actor): array
    {
        return $this->executor->execute($reviewId, $actor, function () use ($reviewId, $command, $actor): array {
            $review = $this->reviews->findFresh($reviewId) ?? throw new NotFound();

            return ReviewDecider::decide($command, $review->toSnapshot(), $actor);
        });
    }

    private function repository(RepositoryId $repositoryId): Repository
    {
        return $this->repositories->find(Ulid::fromString($repositoryId->toString())) ?? throw new NotFound();
    }
}
