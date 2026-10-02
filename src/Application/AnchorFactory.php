<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\FileQuery;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\ReviewClosed;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitFailed;

/**
 * Q2 anchor capture at the review commit, architecture section 5.1. The snapshot always comes from git.
 */
final class AnchorFactory
{
    public function __construct(
        private readonly FetchRepository $fetchRepository,
        private readonly FileQuery $files,
    ) {
    }

    /**
     * Checks the input before it reads the clone, so invalid_input comes before repository_unavailable.
     *
     * @throws InvalidInput          With fields under `anchor`
     * @throws ReviewClosed          The review is closed and its clone is not ready
     * @throws RepositoryUnavailable
     * @throws GitFailed
     */
    public function capture(Review $review, AnchorKind $kind, string $path, ?int $startLine, ?int $endLine, ?string $symbolFqn): Anchor
    {
        try {
            if (AnchorKind::Symbol === $kind) {
                throw InvalidInput::field('kind', 'Symbol anchors are not available.');
            }

            if (null !== $symbolFqn) {
                throw InvalidInput::field('symbol', "Must be null for a {$kind->value} anchor.");
            }

            $repoPath = RepoPath::fromString($path);
            $range = self::range($kind, $startLine, $endLine);
            $gitDir = $this->clonePath($review);

            try {
                $entry = $this->files->entry($gitDir, $review, $repoPath);
            } catch (NotFound) {
                throw InvalidInput::field('path', 'No file has this path at the review commit.');
            }

            $blobSha = BlobSha::fromString($entry->objectId);

            if (null === $range) {
                return Anchor::of(AnchorKind::File, $repoPath, $review->getCommitSha(), $blobSha, null, null, null);
            }

            $text = $this->files->get($gitDir, $review, $entry)->text()
                ?? throw InvalidInput::field('path', "A {$kind->value} anchor needs a renderable file: not binary, not a symlink, at most 1 MiB.");

            // The snapshot lines go into JSON.
            if (!mb_check_encoding($text, 'UTF-8')) {
                throw InvalidInput::field('path', "A {$kind->value} anchor needs a file in UTF-8.");
            }

            return Anchor::of($kind, $repoPath, $review->getCommitSha(), $blobSha, $range, null, SnapshotCapture::capture($text, $range));
        } catch (InvalidInput $error) {
            throw $error->prefixed('anchor');
        }
    }

    /**
     * @return LineRange|null Null for a file anchor
     *
     * @throws InvalidInput
     */
    private static function range(AnchorKind $kind, ?int $startLine, ?int $endLine): ?LineRange
    {
        if (AnchorKind::File === $kind) {
            return null === $startLine && null === $endLine ? null : throw InvalidInput::field('range', 'Must be null for a file anchor.');
        }

        if (null === $startLine || null === $endLine) {
            throw InvalidInput::field('range', "Is required for a {$kind->value} anchor.");
        }

        $range = LineRange::of($startLine, $endLine);
        $violations = Anchor::rangeViolations($kind, $range);

        return [] === $violations ? $range : throw new InvalidInput($violations);
    }

    /**
     * review_closed comes before repository_unavailable in the check order of docs/specs/README.md section 4.
     *
     * @throws ReviewClosed
     * @throws RepositoryUnavailable
     */
    private function clonePath(Review $review): string
    {
        try {
            return $this->fetchRepository->readyClonePath($review->getRepository()->getId());
        } catch (RepositoryUnavailable $unavailable) {
            $closedAt = $review->getClosedAt();

            throw null === $closedAt ? $unavailable : new ReviewClosed($review->getId(), $closedAt);
        }
    }
}
