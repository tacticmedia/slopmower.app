<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RepositoryId;
use App\Entity\GitHubCandidate;
use App\Source\LocalRepositoryCandidate;

final readonly class CandidateList
{
    /**
     * @param list<GitHubCandidate>          $githubCandidates             Sorted by name
     * @param array<int, RepositoryId>       $githubConnectedRepositoryIds By GitHub repository ID
     * @param list<LocalRepositoryCandidate> $localCandidates              Sorted by directory
     * @param array<string, RepositoryId>    $localConnectedRepositoryIds  By local directory
     * @param bool                           $truncated                    More candidates exist than the list holds
     */
    public function __construct(
        public array $githubCandidates,
        public array $githubConnectedRepositoryIds,
        public array $localCandidates,
        public array $localConnectedRepositoryIds,
        public bool $truncated,
        public bool $localEnabled,
        public ?\DateTimeImmutable $githubListedAt,
        public ?string $githubConfigureUrl,
    ) {
    }
}
