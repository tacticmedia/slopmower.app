<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class CommitNotFound extends DomainError
{
    /**
     * @param string $commit The SHA or ref as the caller gave it
     */
    public function __construct(private readonly string $commit)
    {
        parent::__construct("The commit {$commit} is not in the server clone.");
    }

    public function code(): ErrorCode
    {
        return ErrorCode::CommitNotFound;
    }

    /**
     * @return array{commit: string}
     */
    public function details(): array
    {
        return ['commit' => $this->commit];
    }
}
