<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;

/**
 * Pin refs keep reviewed and linked commits in the clone, decision D26. {@see BareClone::REFSPECS} never touch them.
 * A pin never moves. Pin writes and reads do not hold the {@see RepositoryLock}.
 */
final class PinRefs
{
    public const string NAMESPACE = 'refs/holistic-review/';

    public function __construct(
        private readonly GitRunner $runner,
        private readonly TenantDataPaths $paths,
    ) {
    }

    public static function reviewRef(ReviewId $reviewId): string
    {
        return self::NAMESPACE.'reviews/'.$reviewId->toString();
    }

    public static function commitRef(CommitSha $commitSha): string
    {
        return self::NAMESPACE.'commits/'.$commitSha->toString();
    }

    /**
     * @throws PinRefConflict when the pin exists with another commit
     */
    public function pinReview(TenantId $tenantId, RepositoryId $repositoryId, ReviewId $reviewId, CommitSha $commitSha): void
    {
        $this->create($this->paths->cloneDirectory($tenantId, $repositoryId), self::reviewRef($reviewId), $commitSha);
    }

    /**
     * @throws PinRefConflict when the pin exists with another commit
     */
    public function pinCommit(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commitSha): void
    {
        $this->create($this->paths->cloneDirectory($tenantId, $repositoryId), self::commitRef($commitSha), $commitSha);
    }

    public function unpinReview(TenantId $tenantId, RepositoryId $repositoryId, ReviewId $reviewId): void
    {
        $this->delete($this->paths->cloneDirectory($tenantId, $repositoryId), self::reviewRef($reviewId));
    }

    public function unpinCommit(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commitSha): void
    {
        $this->delete($this->paths->cloneDirectory($tenantId, $repositoryId), self::commitRef($commitSha));
    }

    /**
     * @return array<string, CommitSha> the commit of each pin by ref name, sorted by ref name
     */
    public function list(TenantId $tenantId, RepositoryId $repositoryId): array
    {
        $output = $this->runner->mustRun(new GitCommand(
            ['for-each-ref', '--format=%(refname) %(objectname)', '--end-of-options', self::NAMESPACE],
            $this->paths->cloneDirectory($tenantId, $repositoryId),
        ))->output;

        $pins = [];
        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            if ('' !== $line) {
                [$ref, $sha] = explode(' ', $line, 2);
                $pins[$ref] = CommitSha::fromString($sha);
            }
        }

        return $pins;
    }

    private function create(string $cloneDirectory, string $ref, CommitSha $commitSha): void
    {
        $sha = $commitSha->toString();
        // The zero OID as the old value makes git refuse to change an existing ref.
        $command = new GitCommand(['update-ref', '--end-of-options', $ref, $sha, str_repeat('0', \strlen($sha))], $cloneDirectory);
        $result = $this->runner->run($command);
        if ($result->isSuccessful()) {
            return;
        }

        $pinnedSha = $this->pinnedSha($cloneDirectory, $ref);
        if (null === $pinnedSha) {
            throw GitCommandFailed::exited($command, $result->exitCode, $result->errorOutput);
        }
        if (!$pinnedSha->equals($commitSha)) {
            throw new PinRefConflict($ref, $pinnedSha, $commitSha);
        }
    }

    private function delete(string $cloneDirectory, string $ref): void
    {
        $this->runner->mustRun(new GitCommand(['update-ref', '-d', '--end-of-options', $ref], $cloneDirectory));
    }

    private function pinnedSha(string $cloneDirectory, string $ref): ?CommitSha
    {
        $result = $this->runner->run(new GitCommand(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref], $cloneDirectory));

        return $result->isSuccessful() ? CommitSha::fromString(trim($result->output)) : null;
    }
}
