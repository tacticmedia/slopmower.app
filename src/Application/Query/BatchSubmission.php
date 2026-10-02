<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;

/**
 * batch-submission.schema.json.
 */
final readonly class BatchSubmission
{
    /**
     * @param int                                                     $seq            The seq of batch.submitted
     * @param list<RequestSummaryView>                                $openedRequests
     * @param list<array{requestId: RequestId, commentId: CommentId}> $replies
     */
    public function __construct(
        public BatchId $batchId,
        public ReviewId $reviewId,
        public ?string $summary,
        public \DateTimeImmutable $submittedAt,
        public int $seq,
        public array $openedRequests,
        public array $replies,
    ) {
    }
}
