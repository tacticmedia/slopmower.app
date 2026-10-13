<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;

/**
 * D26. The fetch refspecs never touch these refs, so pinned commits survive force-pushes, pruning, and gc.
 */
final class PinRefs
{
    public const string PREFIX = 'refs/slopmower/';

    public const string REVIEWS = self::PREFIX.'reviews/';

    public const string COMMITS = self::PREFIX.'commits/';

    public function __construct(private readonly GitRunner $git)
    {
    }

    public function pinReview(string $gitDir, ReviewId $reviewId, CommitSha $commit): void
    {
        $this->create($gitDir, self::REVIEWS.$reviewId, $commit);
    }

    public function pinCommit(string $gitDir, CommitSha $commit): void
    {
        $this->create($gitDir, self::COMMITS.$commit, $commit);
    }

    public function unpinReview(string $gitDir, ReviewId $reviewId): void
    {
        $this->git->run($gitDir, ['update-ref', '-d', '--end-of-options', self::REVIEWS.$reviewId]);
    }

    public function unpinCommit(string $gitDir, CommitSha $commit): void
    {
        $this->git->run($gitDir, ['update-ref', '-d', '--end-of-options', self::COMMITS.$commit]);
    }

    /**
     * @return array<string, CommitSha> Commit by ref name
     */
    public function list(string $gitDir): array
    {
        $pins = [];

        foreach (explode("\n", trim($this->git->run($gitDir, ['for-each-ref', '--format=%(refname) %(objectname)', self::PREFIX]))) as $line) {
            if ('' !== $line) {
                [$ref, $commit] = explode(' ', $line, 2);
                $pins[$ref] = CommitSha::fromString($commit);
            }
        }

        return $pins;
    }

    /**
     * The zero OID as the old value makes git refuse to move an existing pin.
     *
     * @throws GitFailed When the pin exists with another commit, or the commit is not in the clone
     */
    private function create(string $gitDir, string $ref, CommitSha $commit): void
    {
        try {
            $this->git->run($gitDir, ['update-ref', '--end-of-options', $ref, $commit->toString(), str_repeat('0', strlen($commit->toString()))]);
        } catch (GitFailed $failure) {
            if (!($this->list($gitDir)[$ref] ?? null)?->equals($commit)) {
                throw $failure;
            }
        }
    }
}
