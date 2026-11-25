<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;

/**
 * A commit of {@see CodeReader::blame()}. V17 does not give the email.
 */
final readonly class BlameCommit
{
    /**
     * @param string      $authorEmail     Empty when the commit has none
     * @param string|null $gitHubAvatarUrl Only for an author with a GitHub account
     */
    public function __construct(
        public CommitSha $sha,
        public string $authorName,
        public string $authorEmail,
        public \DateTimeImmutable $authoredAt,
        public string $summary,
        public ?string $gitHubAvatarUrl = null,
    ) {
    }
}
