<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Git\CommitSha;

final class CommitsNotAvailable extends DomainError
{
    /**
     * @param non-empty-list<CommitSha> $pendingCommits
     */
    public function __construct(private readonly array $pendingCommits)
    {
        parent::__construct('A linked commit is not in the server clone: '.implode(', ', $pendingCommits).'.');
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
