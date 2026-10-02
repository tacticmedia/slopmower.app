<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RepositoryId;
use App\Source\LocalRepositoryCandidate;

final readonly class CandidateList
{
    /**
     * @param list<LocalRepositoryCandidate> $localCandidates        Sorted by directory
     * @param array<string, RepositoryId>    $connectedRepositoryIds By local directory
     * @param bool                           $truncated              More local candidates exist than the scan holds
     */
    public function __construct(
        public array $localCandidates,
        public array $connectedRepositoryIds,
        public bool $truncated,
        public bool $localEnabled,
        public ?\DateTimeImmutable $githubListedAt = null,
        public ?string $githubConfigureUrl = null,
    ) {
    }
}
