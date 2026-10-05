<?php

declare(strict_types=1);

namespace App\Anchoring;

use App\Application\Query\FileContent;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Error\InvalidInput;
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
use App\Entity\Repository;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\ObjectType;
use App\Git\TenantDataPaths;
use App\Git\TreeEntry;
use App\Index\BlobSymbols;
use App\Index\ExtractedSymbol;
use App\Repository\LocationCacheEntryRepository;

/**
 * Architecture section 5.4 `locate` on the server clone, with the cache of section 5.2. The caller checks that the
 * clone exists and that the target commit is in it.
 */
final class Locator
{
    public const int MAX_CANDIDATE_FILES = 20;

    public const int MIN_SEARCHED_SNAPSHOT_LINES = 3;

    public const int MAX_EXCERPT_LINES = 400;

    public const int MAX_SEARCHED_BYTES = 1000;

    public function __construct(
        private readonly GitReader $reader,
        private readonly BlobSymbols $blobSymbols,
        private readonly TenantDataPaths $paths,
        private readonly LocationCacheEntryRepository $cache,
        private readonly LocationCacheSql $cacheSql,
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

        $location = $this->compute($repository, $this->gitDir($repository), $anchor, $targetSha);
        $this->cacheSql->insert($repository->getTenantId(), $repository->getId(), $openedEventId, $location);

        return $location;
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

        $gitDir = $this->gitDir($repository);
        $entry = $this->reader->entryAt($gitDir, $location->targetSha, $location->path);
        $content = null === $entry ? null : $this->renderableContent($gitDir, $entry);

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

    private function compute(Repository $repository, string $gitDir, Anchor $anchor, CommitSha $target): Location
    {
        $entry = $this->mappedEntry($gitDir, $anchor, $target);

        if (null === $entry) {
            return $this->searchOtherFiles($gitDir, $anchor, $target, null) ?? Location::orphaned($target, OrphanReason::FileDeleted);
        }

        $path = RepoPath::fromString($entry->path);

        if ($entry->objectId === $anchor->blobSha->toString()) {
            return self::exactOrShifted($anchor, $target, $path, $anchor->range);
        }

        $range = $anchor->range;
        $snapshot = $anchor->snapshot;

        if (AnchorKind::File === $anchor->kind || null === $range || null === $snapshot) {
            return Location::changed($target, $path, null, 1.0);
        }

        $content = $this->renderableContent($gitDir, $entry);

        if (null === $content) {
            return Location::orphaned($target, OrphanReason::ContentNotFound);
        }

        $fileLines = SnapshotCapture::lines($content);
        $symbolRange = $this->symbolRange($repository, $gitDir, $anchor, $entry);

        if (null !== $symbolRange) {
            return SnapshotCapture::normalizedHash(self::region($fileLines, $symbolRange)) === $snapshot->normalizedHash
                ? self::exactOrShifted($anchor, $target, $path, $symbolRange)
                : Location::changed($target, $path, $symbolRange, 1.0);
        }

        $hunks = UnifiedZeroDiffParser::parse($this->reader->unifiedZeroDiff($gitDir, $anchor->blobSha, BlobSha::fromString($entry->objectId)));
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

            return Location::changed($target, $path, $mapped->range, LineSimilarity::between($kept, $snapshot->lines));
        }

        $fuzzy = BestWindow::near($fileLines, $snapshot, $mapped->startLine);

        if (null !== $fuzzy) {
            return Location::changed($target, $path, $fuzzy->range, $fuzzy->score);
        }

        return $this->searchOtherFiles($gitDir, $anchor, $target, $path) ?? Location::orphaned($target, OrphanReason::RegionDeleted);
    }

    /**
     * Architecture section 5.4 `mapPath`. Git pairs a rename only for a path that is gone, so a path that is still a
     * blob maps to itself. A new path that RepoPath refuses counts as deleted.
     */
    private function mappedEntry(string $gitDir, Anchor $anchor, CommitSha $target): ?TreeEntry
    {
        $entry = $this->reader->entryAt($gitDir, $target, $anchor->path);

        if (ObjectType::Blob === $entry?->type) {
            return $entry;
        }

        $newPath = $this->reader->pathAfter($gitDir, $anchor->commitSha, $target, $anchor->path);

        if (null === $newPath || $newPath === $anchor->path->toString()) {
            return null;
        }

        try {
            $renamed = $this->reader->entryAt($gitDir, $target, RepoPath::fromString($newPath));
        } catch (InvalidInput) {
            return null;
        }

        return ObjectType::Blob === $renamed?->type ? $renamed : null;
    }

    /**
     * The one symbol of the target file with the FQN. A parse error gives no symbols (F12), and two symbols with the
     * FQN give none, as in {@see \App\Application\AnchorFactory}.
     */
    private function symbolRange(Repository $repository, string $gitDir, Anchor $anchor, TreeEntry $entry): ?LineRange
    {
        $fqn = AnchorKind::Symbol === $anchor->kind ? $anchor->symbol?->fqn : null;

        if (null === $fqn) {
            return null;
        }

        $matches = array_values(array_filter(
            $this->blobSymbols->of($repository, $gitDir, $entry)->symbols,
            static fn (ExtractedSymbol $symbol): bool => $symbol->fqn === $fqn,
        ));

        return 1 === count($matches) ? $matches[0]->range : null;
    }

    /**
     * Architecture section 5.4 `searchOtherFiles`. One match gives shifted, several give ambiguous.
     *
     * @param RepoPath|null $searchedPath Searched already, so it is no candidate
     */
    private function searchOtherFiles(string $gitDir, Anchor $anchor, CommitSha $target, ?RepoPath $searchedPath): ?Location
    {
        $snapshot = $anchor->snapshot;

        if (null === $snapshot || $snapshot->lineCount < self::MIN_SEARCHED_SNAPSHOT_LINES || !SnapshotMatcher::isDistinctive($snapshot)) {
            return null;
        }

        $candidates = array_filter(
            $this->reader->filesContaining($gitDir, $target, self::searchedText($snapshot)),
            static fn (string $candidate): bool => $candidate !== $searchedPath?->toString(),
        );
        $matches = [];

        foreach (array_slice($candidates, 0, self::MAX_CANDIDATE_FILES) as $candidate) {
            try {
                $path = RepoPath::fromString($candidate);
            } catch (InvalidInput) {
                continue;
            }

            $entry = $this->reader->entryAt($gitDir, $target, $path);
            $content = null === $entry ? null : $this->renderableContent($gitDir, $entry);

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

        foreach ($snapshot->lines as $line) {
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
    private function renderableContent(string $gitDir, TreeEntry $entry): ?string
    {
        if (!$entry->isRenderableFile()) {
            return null;
        }

        $blob = $this->reader->readBlob($gitDir, BlobSha::fromString($entry->objectId));

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

    private function gitDir(Repository $repository): string
    {
        return $this->paths->clonePath($repository->getTenantId(), $repository->getId());
    }
}
