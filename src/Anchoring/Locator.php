<?php

declare(strict_types=1);

namespace App\Anchoring;

use App\Application\CodeReaders;
use App\Application\Query\FileContent;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\LimitExceeded;
use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Location\Location;
use App\Domain\Location\OrphanReason;
use App\Domain\Matching\BestWindow;
use App\Domain\Matching\LineSimilarity;
use App\Domain\Matching\RangeMapper;
use App\Domain\Matching\SnapshotMatcher;
use App\Domain\Matching\UnifiedZeroDiffParser;
use App\Domain\Repository\CodeAccess;
use App\Entity\Repository;
use App\Git\CodeReader;
use App\Git\GitFailed;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Index\BlobSymbols;
use App\Index\ExtractedSymbol;
use App\Repository\LocationCacheEntryRepository;
use App\Repository\RepositoryRepository;

/**
 * Architecture section 5.4 `locate` through the code reader of the repository, with the cache of section 5.2. The caller
 * checks that the code is ready and that the target commit exists. Browser only compares blob SHAs (D114).
 */
final class Locator
{
    public const int MAX_CANDIDATE_FILES = 20;

    public const int MIN_SEARCHED_SNAPSHOT_LINES = 3;

    public const int MAX_EXCERPT_LINES = 400;

    public const int MAX_SEARCHED_BYTES = 1000;

    public function __construct(
        private readonly CodeReaders $readers,
        private readonly BlobSymbols $blobSymbols,
        private readonly LocationCacheEntryRepository $cache,
        private readonly LocationCacheSql $cacheSql,
        private readonly RepositoryRepository $repositories,
    ) {
    }

    /**
     * @param EventId $openedEventId The request.opened event of the anchor, the cache key with the target
     *
     * @throws GitFailed
     */
    public function locate(Repository $repository, EventId $openedEventId, Anchor $anchor, CommitSha $targetSha): Location
    {
        $cached = $this->cache->findOneByKey($openedEventId, $targetSha);

        if (null !== $cached) {
            return $cached->toLocation();
        }

        $codeAccess = $repository->getCodeAccess();
        $location = $this->compute($repository, $this->readers->of($repository), $anchor, $targetSha);

        // A location of the mode before an R10 switch must not outlive the deletion of the cache (D114).
        if ($codeAccess === $this->repositories->findFresh($repository->getId())?->getCodeAccess()) {
            $this->cacheSql->insert($repository->getTenantId(), $repository->getId(), $openedEventId, $location);
        }

        return $location;
    }

    /**
     * The path of the anchor file at the target commit, after a rename. An orphaned location has no path, so this
     * tells whether its file still exists.
     *
     * @return RepoPath|null Null when the file is gone
     *
     * @throws GitFailed
     */
    public function mappedPath(Repository $repository, Anchor $anchor, CommitSha $targetSha): ?RepoPath
    {
        $entry = $this->mappedEntry($this->readers->of($repository), $anchor, $targetSha);

        return null === $entry ? null : RepoPath::fromString($entry->path);
    }

    /**
     * The located lines with $contextLines on each side. A location without a range, of a file anchor, shows the file.
     *
     * @return CodeExcerpt|null Null for an orphaned location, an empty file, and a file that is not renderable or not UTF-8
     *
     * @throws GitFailed
     */
    public function excerpt(Repository $repository, Location $location, int $contextLines): ?CodeExcerpt
    {
        if (null === $location->path) {
            return null;
        }

        $reader = $this->readers->of($repository);
        $entry = $reader->entryAt($location->targetSha, $location->path);
        $content = null === $entry ? null : $this->renderableContent($reader, $entry);

        // The lines go into JSON.
        if (null === $content || !mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        $fileLines = SnapshotCapture::lines($content);
        $range = $location->range;
        $startLine = null === $range ? 1 : max(1, $range->startLine - $contextLines);
        $endLine = null === $range ? count($fileLines) : min(count($fileLines), $range->endLine + $contextLines);

        if ($endLine < $startLine) {
            return null;
        }

        $truncated = $endLine - $startLine + 1 > self::MAX_EXCERPT_LINES;
        $endLine = $truncated ? $startLine + self::MAX_EXCERPT_LINES - 1 : $endLine;
        $lines = array_map(
            static fn (string $line): string => str_ends_with($line, "\r") ? substr($line, 0, -1) : $line,
            array_slice($fileLines, $startLine - 1, $endLine - $startLine + 1),
        );

        return new CodeExcerpt($location->targetSha, $location->path, $startLine, $endLine, $lines, $truncated);
    }

    private function compute(Repository $repository, CodeReader $reader, Anchor $anchor, CommitSha $target): Location
    {
        $entry = $this->mappedEntry($reader, $anchor, $target);

        if (CodeAccess::Browser === $repository->getCodeAccess()) {
            return match (true) {
                null === $entry => Location::orphaned($target, OrphanReason::FileDeleted),
                $entry->objectId === $anchor->blobSha->toString() => self::exactOrShifted($anchor, $target, RepoPath::fromString($entry->path), $anchor->range),
                default => Location::unverified($target, $anchor->path, $anchor->range),
            };
        }

        if (null === $entry) {
            return $this->searchOtherFiles($reader, $this->snapshotText($reader, $anchor), $target, null) ?? Location::orphaned($target, OrphanReason::FileDeleted);
        }

        $path = RepoPath::fromString($entry->path);

        if ($entry->objectId === $anchor->blobSha->toString()) {
            return self::exactOrShifted($anchor, $target, $path, $anchor->range);
        }

        $range = $anchor->range;

        if (AnchorKind::File === $anchor->kind || null === $range) {
            return Location::changed($target, $path, null, 1.0);
        }

        $snapshot = $this->snapshotText($reader, $anchor);

        if (null === $snapshot && null === $anchor->snapshot) {
            return Location::changed($target, $path, null, 1.0);
        }

        $content = $this->renderableContent($reader, $entry);

        if (null === $content || null === $snapshot) {
            return Location::orphaned($target, OrphanReason::ContentNotFound);
        }

        $fileLines = SnapshotCapture::lines($content);
        $symbolRange = $this->symbolRange($repository, $reader, $anchor, $entry);

        if (null !== $symbolRange) {
            return SnapshotCapture::normalizedHash(self::region($fileLines, $symbolRange)) === $snapshot->normalizedHash
                ? self::exactOrShifted($anchor, $target, $path, $symbolRange)
                : Location::changed($target, $path, $symbolRange, 1.0);
        }

        $hunks = UnifiedZeroDiffParser::parse($reader->unifiedZeroDiff($anchor->blobSha, BlobSha::fromString($entry->objectId)));
        $mapped = RangeMapper::mapRange($range, $hunks);

        if ($mapped->untouched && null !== $mapped->range) {
            return self::exactOrShifted($anchor, $target, $path, $mapped->range);
        }

        $match = SnapshotMatcher::nearestMatch($fileLines, $snapshot, $mapped->startLine);

        if (null !== $match) {
            return null === $match->range ? Location::orphaned($target, OrphanReason::Ambiguous) : self::exactOrShifted($anchor, $target, $path, $match->range);
        }

        if (null !== $mapped->range) {
            $kept = SnapshotCapture::keptLines(self::region($fileLines, $mapped->range));

            return Location::changed($target, $path, $mapped->range, LineSimilarity::between($kept, $snapshot->textLines()));
        }

        $fuzzy = BestWindow::near($fileLines, $snapshot, $mapped->startLine);

        if (null !== $fuzzy) {
            return Location::changed($target, $path, $fuzzy->range, $fuzzy->score);
        }

        return $this->searchOtherFiles($reader, $snapshot, $target, $path) ?? Location::orphaned($target, OrphanReason::RegionDeleted);
    }

    /**
     * D113: the anchor with the code text of its snapshot, rebuilt from its blob. The text stays null when the rebuild
     * fails, for example for a blob that GitHub no longer serves.
     */
    public function withSnapshotText(CodeReader $reader, Anchor $anchor): Anchor
    {
        try {
            $snapshot = $this->snapshotText($reader, $anchor);
        } catch (RepositoryUnavailable|RateLimited|GitFailed) {
            $snapshot = null;
        }

        return null === $snapshot || $snapshot === $anchor->snapshot
            ? $anchor
            : Anchor::of($anchor->kind, $anchor->path, $anchor->commitSha, $anchor->blobSha, $anchor->range, $anchor->symbol, $snapshot);
    }

    /**
     * Also captures the snapshot of a line or range anchor of Browser only, which has none (D114). Browser only never
     * calls it.
     *
     * @return ContentSnapshot|null The stored snapshot with its text, else rebuilt from the anchor blob. Null without
     *                              a range, a blob, or the lines of the range.
     */
    private function snapshotText(CodeReader $reader, Anchor $anchor): ?ContentSnapshot
    {
        $snapshot = $anchor->snapshot;

        if (null !== $snapshot?->lines || null === $anchor->range) {
            return $snapshot;
        }

        $blob = $reader->readBlob($anchor->blobSha);

        try {
            return null === $blob ? null : SnapshotCapture::capture($blob->bytes, $anchor->range);
        } catch (InvalidInput) {
            // The range of Browser only is not checked against the file.
            return null;
        }
    }

    /**
     * Architecture section 5.4 `mapPath`. Git pairs a rename only for a path that is gone, so a path that is still a
     * blob maps to itself. A new path that RepoPath refuses counts as deleted.
     */
    private function mappedEntry(CodeReader $reader, Anchor $anchor, CommitSha $target): ?TreeEntry
    {
        $entry = $reader->entryAt($target, $anchor->path);

        if (ObjectType::Blob === $entry?->type) {
            return $entry;
        }

        $newPath = $reader->pathAfter($anchor->commitSha, $target, $anchor->path);

        if (null === $newPath || $newPath === $anchor->path->toString()) {
            return null;
        }

        try {
            $renamed = $reader->entryAt($target, RepoPath::fromString($newPath));
        } catch (InvalidInput) {
            return null;
        }

        return ObjectType::Blob === $renamed?->type ? $renamed : null;
    }

    /**
     * The one symbol of the target file with the FQN. A parse error gives no symbols (F12), and two symbols with the
     * FQN give none, as in {@see \App\Application\AnchorFactory}.
     */
    private function symbolRange(Repository $repository, CodeReader $reader, Anchor $anchor, TreeEntry $entry): ?LineRange
    {
        $fqn = AnchorKind::Symbol === $anchor->kind ? $anchor->symbol?->fqn : null;

        if (null === $fqn) {
            return null;
        }

        $matches = array_values(array_filter(
            $this->blobSymbols->of($repository, $reader, $entry)->symbols,
            static fn (ExtractedSymbol $symbol): bool => $symbol->fqn === $fqn,
        ));

        return 1 === count($matches) ? $matches[0]->range : null;
    }

    /**
     * Architecture section 5.4 `searchOtherFiles`. One match gives shifted, several give ambiguous.
     *
     * @param RepoPath|null $searchedPath Searched already, so it is no candidate
     */
    private function searchOtherFiles(CodeReader $reader, ?ContentSnapshot $snapshot, CommitSha $target, ?RepoPath $searchedPath): ?Location
    {
        if (null === $snapshot || $snapshot->lineCount < self::MIN_SEARCHED_SNAPSHOT_LINES || !SnapshotMatcher::isDistinctive($snapshot)) {
            return null;
        }

        try {
            $containing = $reader->filesContaining($target, self::searchedText($snapshot));
        } catch (LimitExceeded) {
            // A repository above `repository_size` gets no scratch copy, so no search.
            $containing = [];
        }

        $candidates = array_filter($containing, static fn (string $candidate): bool => $candidate !== $searchedPath?->toString());
        $matches = [];

        foreach (array_slice($candidates, 0, self::MAX_CANDIDATE_FILES) as $candidate) {
            try {
                $path = RepoPath::fromString($candidate);
            } catch (InvalidInput) {
                continue;
            }

            $entry = $reader->entryAt($target, $path);
            $content = null === $entry ? null : $this->renderableContent($reader, $entry);

            foreach (null === $content ? [] : SnapshotMatcher::exactMatches(SnapshotCapture::lines($content), $snapshot) as $range) {
                $matches[] = [$path, $range];
            }
        }

        return match (count($matches)) {
            0 => null,
            1 => Location::shifted($target, $matches[0][0], $matches[0][1]),
            default => Location::orphaned($target, OrphanReason::Ambiguous),
        };
    }

    /**
     * Trimmed, so the search finds a re-indented copy. Cut to MAX_SEARCHED_BYTES, because an argument above 128 KiB
     * fails to start git, and exactMatches() checks each candidate anyway.
     */
    private static function searchedText(ContentSnapshot $snapshot): string
    {
        $longest = '';

        foreach ($snapshot->textLines() as $line) {
            $trimmed = trim($line);

            if (strlen($trimmed) > strlen($longest) && !str_contains($trimmed, "\0")) {
                $longest = $trimmed;
            }
        }

        return substr($longest, 0, self::MAX_SEARCHED_BYTES);
    }

    /**
     * The rules of {@see FileContent::isRenderable()}: a regular file of at most 1 MiB, not binary.
     */
    private function renderableContent(CodeReader $reader, TreeEntry $entry): ?string
    {
        if (!$entry->isRenderableFile()) {
            return null;
        }

        $blob = $reader->readBlob(BlobSha::fromString($entry->objectId));

        return null === $blob || $blob->isBinary() ? null : $blob->bytes;
    }

    private static function exactOrShifted(Anchor $anchor, CommitSha $target, RepoPath $path, ?LineRange $range): Location
    {
        $samePlace = $path->equals($anchor->path)
            && (null === $range ? null === $anchor->range : null !== $anchor->range && $range->equals($anchor->range));

        return $samePlace ? Location::exact($target, $path, $range) : Location::shifted($target, $path, $range);
    }

    /**
     * @param list<string> $fileLines
     *
     * @return list<string>
     */
    private static function region(array $fileLines, LineRange $range): array
    {
        return array_slice($fileLines, $range->startLine - 1, $range->lineCount());
    }
}
