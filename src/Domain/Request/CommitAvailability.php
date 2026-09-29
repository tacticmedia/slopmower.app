<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Git\CommitSha;

/**
 * The commits that the application found in the server clone before it decides.
 * A commit that is not listed counts as missing.
 */
final readonly class CommitAvailability
{
    /**
     * @var array<string, AvailableCommit>
     */
    private array $availableCommitsBySha;

    public function __construct(AvailableCommit ...$availableCommits)
    {
        $availableCommitsBySha = [];
        foreach ($availableCommits as $availableCommit) {
            $availableCommitsBySha[$availableCommit->commitSha->toString()] = $availableCommit;
        }
        $this->availableCommitsBySha = $availableCommitsBySha;
    }

    public static function none(): self
    {
        return new self();
    }

    public function find(CommitSha $commitSha): ?AvailableCommit
    {
        return $this->availableCommitsBySha[$commitSha->toString()] ?? null;
    }

    public function contains(CommitSha $commitSha): bool
    {
        return null !== $this->find($commitSha);
    }
}
