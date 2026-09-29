<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class CommitNotFound extends DomainError
{
    /**
     * @param string $commit the SHA or ref as the caller gave it, not resolved
     */
    public function __construct(private readonly string $commit)
    {
        parent::__construct(\sprintf('Commit %s is not in the server clone after a fetch.', $commit));
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
