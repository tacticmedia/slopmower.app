<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Identity\RepositoryId;

final class RepositoryUnavailable extends DomainError
{
    public function __construct(
        private readonly RepositoryId $repositoryId,
        private readonly RepositoryUnavailableReason $reason,
    ) {
        parent::__construct(\sprintf(match ($reason) {
            RepositoryUnavailableReason::Cloning => 'Repository %s is still cloning.',
            RepositoryUnavailableReason::CloneFailed => 'The clone of repository %s failed.',
            RepositoryUnavailableReason::FetchFailed => 'The fetch of repository %s failed.',
            RepositoryUnavailableReason::Removed => 'Access to repository %s was removed.',
        }, $repositoryId));
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
