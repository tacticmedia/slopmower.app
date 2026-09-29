<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Git\CommitSha;

final class CommitsNotAvailable extends DomainError
{
    /**
     * @var non-empty-list<CommitSha>
     */
    private readonly array $pendingCommits;

    /**
     * @param non-empty-list<CommitSha> $pendingCommits duplicates are dropped
     */
    public function __construct(array $pendingCommits)
    {
        $pendingCommitsBySha = [];
        foreach ($pendingCommits as $commit) {
            $pendingCommitsBySha[$commit->toString()] ??= $commit;
        }
        $this->pendingCommits = array_values($pendingCommitsBySha);

        $count = \count($this->pendingCommits);
        parent::__construct(1 === $count
            ? '1 linked commit is not in the server clone after a fetch. Push it, then try again.'
            : \sprintf('%d linked commits are not in the server clone after a fetch. Push them, then try again.', $count));
    }

    public function code(): ErrorCode
    {
        return ErrorCode::CommitsNotAvailable;
    }

    /**
     * @return array{pendingCommits: non-empty-list<CommitSha>}
     */
    public function details(): array
    {
        return ['pendingCommits' => $this->pendingCommits];
    }
}
