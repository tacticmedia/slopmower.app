<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\FetchRepository;
use App\Domain\Error\DomainError;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitReader;
use App\Git\TreeEntry;
use App\Index\BlobSymbols;
use App\Index\IndexSql;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Message\IndexReview;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the path list and the symbols of the review commit. A failure is recorded in the review and not retried:
 * `app:index:rebuild` queues the job again.
 */
#[AsMessageHandler]
final class IndexReviewHandler
{
    private const float PUBLISH_INTERVAL_SECONDS = 1.0;

    /** In debug mode the Doctrine profiler keeps every statement of a message, so blobs are written in batches. */
    private const int WRITE_BATCH_ROWS = 1000;

    private const string FAILURE_MESSAGE = 'The index job failed.';

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
        private readonly BlobSymbols $blobSymbols,
        private readonly IndexSql $indexSql,
        private readonly EntityManagerInterface $entityManager,
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IndexReview $job): void
    {
        $review = $this->reviews->findFresh($job->reviewId);

        if (null === $review) {
            return;
        }

        $startedAt = $this->clock->now();
        $review->startIndex($startedAt);
        $this->flushAndPublish($review);

        try {
            $this->index($review, self::unixSeconds($startedAt));
            $review->finishIndex($this->clock->now());
        } catch (\Throwable $failure) {
            $this->logger->error('The index job of the review {reviewId} failed.', ['reviewId' => $job->reviewId->toString(), 'exception' => $failure]);
            $review->failIndex($failure instanceof DomainError ? $failure->getMessage() : self::FAILURE_MESSAGE, $this->clock->now());
        }

        $this->flushAndPublish($review);
    }

    /**
     * Inserts every listed path. Rows of the commit that another review or an earlier run stored stay.
     * Publishes the progress at most once per {@see PUBLISH_INTERVAL_SECONDS}, after a write of the parsed blobs.
     */
    private function index(Review $review, float $publishedAtUnixSeconds): void
    {
        $repository = $review->getRepository();
        $commit = $review->getCommitSha();
        $gitDir = $this->fetchRepository->readyClonePath($repository->getId());
        // V6 to V9 take only paths that RepoPath accepts, and git accepts more.
        $entries = array_values(array_filter($this->reader->lsTreeRecursive($gitDir, $commit), self::hasRepoPath(...)));
        $this->indexSql->insertFiles($repository->getTenantId(), $repository->getId(), $commit, $entries);
        $symbolCounts = $this->indexSql->storedSymbolCounts($repository->getTenantId(), $repository->getId(), $commit);
        $filesTotal = count($entries);
        $symbolsTotal = 0;
        $parsed = [];
        $parsedRows = 0;
        $review->recordIndexProgress($filesTotal, 0, 0);

        foreach ($entries as $filesDone => $entry) {
            if ($this->blobSymbols->supports(RepoPath::fromString($entry->path))) {
                if (!isset($symbolCounts[$entry->objectId])) {
                    $table = $this->blobSymbols->parse($gitDir, $entry);
                    $symbolCounts[$entry->objectId] = count($table->symbols ?? []);

                    if (null !== $table) {
                        $parsed[$entry->objectId] = $table;
                        $parsedRows += 1 + count($table->symbols);
                    }
                }

                $symbolsTotal += $symbolCounts[$entry->objectId];
            }

            $review->recordIndexProgress($filesTotal, $filesDone + 1, $symbolsTotal);
            $nowUnixSeconds = self::unixSeconds($this->clock->now());
            $publish = $nowUnixSeconds - $publishedAtUnixSeconds >= self::PUBLISH_INTERVAL_SECONDS;

            if ($publish || $parsedRows >= self::WRITE_BATCH_ROWS) {
                $this->indexSql->insertBlobs($repository->getTenantId(), $repository->getId(), $parsed);
                $parsed = [];
                $parsedRows = 0;
            }

            if ($publish) {
                $this->flushAndPublish($review);
                $publishedAtUnixSeconds = $nowUnixSeconds;
            }
        }

        $this->indexSql->insertBlobs($repository->getTenantId(), $repository->getId(), $parsed);
    }

    /**
     * The flush commits first, so a client that reloads the review reads the published state.
     */
    private function flushAndPublish(Review $review): void
    {
        $this->entityManager->flush();
        $this->publisher->publish($this->topics->review($review->getTenantId(), $review->getId()), ['type' => 'index', 'reviewId' => $review->getId()->toString()]);
    }

    private static function unixSeconds(\DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }

    private static function hasRepoPath(TreeEntry $entry): bool
    {
        try {
            RepoPath::fromString($entry->path);

            return true;
        } catch (InvalidInput) {
            return false;
        }
    }
}
