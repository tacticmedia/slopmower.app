<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\FileQuery;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\ReviewClosed;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitFailed;
use App\Git\TreeEntry;
use App\Index\BlobSymbols;
use App\Index\ExtractedSymbol;

/**
 * Q2 anchor capture at the review commit, architecture section 5.1. The snapshot always comes from git.
 */
final class AnchorFactory
{
    public function __construct(
        private readonly FetchRepository $fetchRepository,
        private readonly FileQuery $files,
        private readonly BlobSymbols $blobSymbols,
    ) {
    }

    /**
     * Checks the kind rules before it reads the clone, so their invalid_input comes before repository_unavailable.
     *
     * @throws InvalidInput          With fields under `anchor`
     * @throws ReviewClosed          The review is closed and its clone is not ready
     * @throws RepositoryUnavailable
     * @throws GitFailed
     */
    public function capture(Review $review, AnchorKind $kind, string $path, ?int $startLine, ?int $endLine, ?string $symbolFqn): Anchor
    {
        try {
            $isSymbol = AnchorKind::Symbol === $kind;

            if ($isSymbol !== (null !== $symbolFqn)) {
                throw InvalidInput::field('symbol', $isSymbol ? 'Is required for a symbol anchor.' : "Must be null for a {$kind->value} anchor.");
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

            if (AnchorKind::File === $kind) {
                return Anchor::of(AnchorKind::File, $repoPath, $review->getCommitSha(), $blobSha, null, null, null);
            }

            $text = $this->files->get($gitDir, $review, $entry)->text()
                ?? throw InvalidInput::field('path', "A {$kind->value} anchor needs a renderable file: not binary, not a symlink, at most 1 MiB.");

            // The snapshot lines go into JSON.
            if (!mb_check_encoding($text, 'UTF-8')) {
                throw InvalidInput::field('path', "A {$kind->value} anchor needs a file in UTF-8.");
            }

            [$range, $symbol] = null === $symbolFqn ? [$range, null] : $this->symbol($review, $gitDir, $entry, $symbolFqn);
            \assert(null !== $range);

            return Anchor::of($kind, $repoPath, $review->getCommitSha(), $blobSha, $range, $symbol, SnapshotCapture::capture($text, $range));
        } catch (InvalidInput $error) {
            throw $error->prefixed('anchor');
        }
    }

    /**
     * @return LineRange|null Null for a file or symbol anchor
     *
     * @throws InvalidInput
     */
    private static function range(AnchorKind $kind, ?int $startLine, ?int $endLine): ?LineRange
    {
        if (AnchorKind::File === $kind || AnchorKind::Symbol === $kind) {
            return null === $startLine && null === $endLine ? null : throw InvalidInput::field('range', "Must be null for a {$kind->value} anchor.");
        }

        if (null === $startLine || null === $endLine) {
            throw InvalidInput::field('range', "Is required for a {$kind->value} anchor.");
        }

        $range = LineRange::of($startLine, $endLine);
        $violations = Anchor::rangeViolations($kind, $range);

        return [] === $violations ? $range : throw new InvalidInput($violations);
    }

    /**
     * Decision D7: the range of the symbol includes its doc comment.
     *
     * @return array{LineRange, SymbolRef}
     *
     * @throws InvalidInput
     * @throws GitFailed
     */
    private function symbol(Review $review, string $gitDir, TreeEntry $entry, string $fqn): array
    {
        $table = $this->blobSymbols->of($review->getRepository(), $gitDir, $entry);
        $matches = array_values(array_filter($table->symbols, static fn (ExtractedSymbol $symbol): bool => $symbol->fqn === $fqn));

        if (null === $table->language || [] === $matches) {
            throw InvalidInput::field('symbol.fqn', 'No symbol of the file has this FQN.');
        }

        if (1 !== \count($matches)) {
            throw InvalidInput::field('symbol.fqn', 'Several symbols of the file have this FQN.');
        }

        [$symbol] = $matches;

        return [$symbol->range, SymbolRef::of($table->language, $symbol->kind, $symbol->fqn)];
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
            $review->assertOpen();

            throw $unavailable;
        }
    }
}
