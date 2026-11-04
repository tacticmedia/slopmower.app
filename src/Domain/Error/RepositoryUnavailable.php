<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Identity\RepositoryId;

final class RepositoryUnavailable extends DomainError
{
    public function __construct(
        private readonly RepositoryId $repositoryId,
        private readonly RepositoryUnavailableReason $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("The repository is unavailable: {$reason->value}.", 0, $previous);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::RepositoryUnavailable;
    }

    /**
     * @return array{repositoryId: RepositoryId, reason: RepositoryUnavailableReason}
     */
    public function details(): array
    {
        return ['repositoryId' => $this->repositoryId, 'reason' => $this->reason];
    }
}
