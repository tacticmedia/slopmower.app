<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RequestId;
use App\Git\CommitDetails;

/**
 * commit-diff.schema.json.
 */
final readonly class CommitDiffView
{
    /**
     * @param list<CommitDiffFileView> $files
     */
    public function __construct(
        public RequestId $requestId,
        public CommitDetails $commit,
        public ?LocationView $location,
        public array $files,
        public bool $truncated,
    ) {
    }
}
