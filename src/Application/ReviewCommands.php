<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\RepositoryChanged;
use App\Domain\Actor\Actor;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\StartReview;
use App\Domain\Review\ReviewDecider;
use App\Entity\Repository;
use App\Entity\Review;
use App\EventStore\CommandExecutor;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use App\Message\IndexReview;
use App\Repository\CommitLinkRepository;
use App\Repository\DeletionSql;
use App\Repository\DraftRepository;
use App\Repository\RepositoryRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ReviewCommands
{
    private const int TITLE_MAX_CHARACTERS = 200;

    private const int TITLE_SHA_CHARACTERS = 12;

    private const string TITLE_SEPARATOR = ' @ ';

    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositories,
        private readonly ReviewRepository $reviews,
        private readonly DraftRepository $drafts,
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly PinRefs $pins,
        private readonly MessageBusInterface $bus,
        private readonly DeletionSql $deletion,
        private readonly CommitLinkRepository $links,
        private readonly TenantDataPaths $paths,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * V1: fetches unless the ref is a full SHA in the clone, resolves the ref, pins the commit (D26), records
     * review.started, and queues the index job. A failed record deletes the pin.
     *
     * @param RefName|null $ref   Null for the default branch
     * @param string|null  $title Null for the default title of {@see self::defaultTitle()}
     *
     * @throws NotFound
     * @throws RepositoryUnavailable
     * @throws CommitNotFound
     * @throws InvalidInput          An ambiguous SHA prefix, or no ref and no default branch
     * @throws GitFailed
     */
    public function start(RepositoryId $repositoryId, ?RefName $ref, ?string $title, Actor $actor): ReviewId
    {
        $gitDir = $this->fetchRepository->readyClonePath($repositoryId);

        if (!$this->isFullShaInClone($gitDir, $ref)) {
            $this->fetchRepository->fetchNow($repositoryId);
        }

        $ref ??= $this->defaultBranch($repositoryId);
        $commit = $this->reader->resolveCommit($gitDir, $ref);
        $reviewId = ReviewId::generate();
        $this->pins->pinReview($gitDir, $reviewId, $commit);

        try {
            $this->recordStarted($reviewId, $repositoryId, new StartReview($title ?? self::defaultTitle($ref, $commit), $ref, $commit), $actor);
        } catch (\Throwable $failure) {
            $this->pins->unpinReview($gitDir, $reviewId);

            throw $failure;
        }

        $this->bus->dispatch(new IndexReview($reviewId));

        return $reviewId;
    }

    /**
     * The review.started transaction of {@see start()}, which pins the commit first. Creates the review row, because
     * the repository is not event data.
     *
     * @return list<RecordedEvent>
     *
     * @throws NotFound
     * @throws RepositoryUnavailable The access to the repository was removed
     */
    public function recordStarted(ReviewId $reviewId, RepositoryId $repositoryId, StartReview $command, Actor $actor): array
    {
        return $this->executor->execute(
            $reviewId,
            $actor,
            function () use ($reviewId, $repositoryId, $command, $actor): array {
                $repository = $this->repositories->findFresh($repositoryId) ?? throw new NotFound();

                if (RepositoryAccess::Removed === $repository->getAccess()) {
                    throw new RepositoryUnavailable($repositoryId, RepositoryUnavailableReason::Removed);
                }

                return ReviewDecider::decide($command, $this->reviews->findFresh($reviewId)?->toSnapshot(), $actor);
            },
            function (array $recorded) use ($reviewId, $repositoryId, $command, $actor): void {
                $started = $recorded[0] ?? throw new \LogicException('review.started is missing.');
                $this->entityManager->persist(new Review($reviewId, $this->repository($repositoryId), $command->title, $command->ref, $command->commitSha, $actor, $started->occurredAt, $started->seq));
            },
        );
    }

    /**
     * Deletes the drafts of the closing user in the review, in the transaction of review.closed.
     *
     * @return list<RecordedEvent>
     *
     * @throws NotFound
     * @throws ReviewClosed
     */
    public function close(ReviewId $reviewId, CloseReview $command, Actor $actor): array
    {
        return $this->executor->execute(
            $reviewId,
            $actor,
            function () use ($reviewId, $command, $actor): array {
                $review = $this->reviews->findFresh($reviewId) ?? throw new NotFound();

                return ReviewDecider::decide($command, $review->toSnapshot(), $actor);
            },
            function () use ($reviewId, $actor): void {
                $this->drafts->deleteOfUser($actor->userId ?? throw new \LogicException('Only a user closes a review.'), $reviewId);
            },
        );
    }

    /**
     * V13, for open and closed reviews, also of a removed repository. The index stays: it belongs to the commit.
     * The `repository` live update follows, because `openReviewCount` can change.
     *
     * @throws NotFound
     */
    public function delete(ReviewId $reviewId): void
    {
        $review = $this->reviews->findFresh($reviewId) ?? throw new NotFound();
        $tenantId = $review->getTenantId();
        $repositoryId = $review->getRepository()->getId();
        $linkedShas = [];

        $this->executor->delete($tenantId, function () use ($tenantId, $reviewId, &$linkedShas): void {
            $linkedShas = $this->links->commitShasOfReview($reviewId);
            $this->deletion->deleteReview($tenantId, $reviewId);
        });

        $this->unpinDeletedReview($this->paths->clonePath($tenantId, $repositoryId), $repositoryId, $reviewId, $linkedShas);
        $this->dispatcher->dispatch(new RepositoryChanged($tenantId, $repositoryId));
    }

    /**
     * After the commit. A commit pin stays while another link in the repository names the commit.
     *
     * @param list<CommitSha> $linkedShas
     */
    private function unpinDeletedReview(string $gitDir, RepositoryId $repositoryId, ReviewId $reviewId, array $linkedShas): void
    {
        if (!is_dir($gitDir)) {
            return;
        }

        try {
            $this->pins->unpinReview($gitDir, $reviewId);

            foreach ($linkedShas as $commitSha) {
                if (!$this->links->isLinkedInRepository($repositoryId, $commitSha)) {
                    $this->pins->unpinCommit($gitDir, $commitSha);
                }
            }
        } catch (GitFailed $failure) {
            // The rows are deleted. A clone that DeleteCloneJob removes meanwhile has no pins left.
            $this->logger->warning('The pins of the deleted review {reviewId} could not be deleted.', ['reviewId' => $reviewId->toString(), 'exception' => $failure]);
        }
    }

    private function isFullShaInClone(string $gitDir, ?RefName $ref): bool
    {
        $sha = null === $ref ? null : self::fullSha($ref);

        return null !== $sha && $this->reader->hasCommit($gitDir, $sha);
    }

    private static function fullSha(RefName $ref): ?CommitSha
    {
        try {
            return CommitSha::fromString($ref->toString());
        } catch (InvalidInput) {
            return null;
        }
    }

    /**
     * @throws NotFound
     * @throws InvalidInput
     */
    private function defaultBranch(RepositoryId $repositoryId): RefName
    {
        $branch = ($this->repositories->findFresh($repositoryId) ?? throw new NotFound())->getDefaultBranch();

        return null === $branch ? throw InvalidInput::field('ref', 'The repository has no default branch. Name a ref.') : RefName::fromString($branch);
    }

    /**
     * Cuts the ref part to fit the title limit. A full SHA ref gives the short SHA alone.
     */
    private static function defaultTitle(RefName $ref, CommitSha $commit): string
    {
        $shortSha = substr($commit->toString(), 0, self::TITLE_SHA_CHARACTERS);

        if (null !== self::fullSha($ref)) {
            return $shortSha;
        }

        $suffix = self::TITLE_SEPARATOR.$shortSha;

        return mb_substr($ref->toString(), 0, self::TITLE_MAX_CHARACTERS - mb_strlen($suffix)).$suffix;
    }

    private function repository(RepositoryId $repositoryId): Repository
    {
        return $this->repositories->find($repositoryId) ?? throw new NotFound();
    }
}
