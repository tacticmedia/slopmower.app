<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\IndexedBlob;
use App\Entity\Repository;
use App\Entity\Symbol;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\TreeEntry;
use App\Repository\IndexedBlobRepository;
use App\Repository\SymbolRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The one parse-on-demand path. Only a regular, non-binary file of at most 1 MiB has symbols. The extractor of a file
 * follows its {@see FileLanguage}.
 */
final class BlobSymbols
{
    /**
     * @param iterable<SymbolExtractor> $extractors
     */
    public function __construct(
        #[AutowireIterator(SymbolExtractor::class)]
        private readonly iterable $extractors,
        private readonly IndexedBlobRepository $indexedBlobs,
        private readonly SymbolRepository $symbols,
        private readonly IndexSql $indexSql,
        private readonly GitReader $reader,
    ) {
    }

    public function supports(RepoPath $path): bool
    {
        return null !== $this->extractorFor(FileLanguage::of($path));
    }

    /**
     * The stored symbols of the blob, or parses it and stores the result. A blob that is stored for another language,
     * for example the same bytes at `a.js` and `a.ts`, is parsed and not stored.
     *
     * @param string    $gitDir The clone of $repository
     * @param TreeEntry $entry  A blob entry whose path RepoPath accepts
     *
     * @throws GitFailed
     */
    public function of(Repository $repository, string $gitDir, TreeEntry $entry): SymbolTable
    {
        $language = FileLanguage::of(RepoPath::fromString($entry->path));

        if (null === $language || null === $this->extractorFor($language)) {
            return SymbolTable::unsupported();
        }

        $criteria = ['repositoryId' => $repository->getId(), 'blobSha' => BlobSha::fromString($entry->objectId)];
        $indexed = $entry->isRenderableFile() ? $this->indexedBlobs->findOneBy($criteria) : null;

        if ($indexed instanceof IndexedBlob && $language === $indexed->getLanguage()) {
            $symbols = array_map(static fn (Symbol $symbol): ExtractedSymbol => $symbol->toExtracted(), $this->symbols->findBy($criteria));

            return new SymbolTable($language, $indexed->hasParseError(), $symbols);
        }

        $table = $this->parse($gitDir, $entry);

        if (null === $table) {
            return new SymbolTable($language, false, []);
        }

        if (!$indexed instanceof IndexedBlob) {
            $this->indexSql->insertBlobs($repository->getTenantId(), $repository->getId(), [$entry->objectId => $table]);
        }

        return $table;
    }

    /**
     * Parses without a lookup and without a write, for the index job, which skips the blobs that have an IndexedBlob
     * row and writes the others in batches.
     *
     * @param TreeEntry $entry A blob or submodule entry whose path RepoPath accepts
     *
     * @return SymbolTable|null Null for an entry that gets no IndexedBlob row: no extractor supports it, it is not a
     *                          renderable file, or it is binary
     *
     * @throws GitFailed
     */
    public function parse(string $gitDir, TreeEntry $entry): ?SymbolTable
    {
        $language = FileLanguage::of(RepoPath::fromString($entry->path));
        $extractor = $this->extractorFor($language);

        if (null === $language || null === $extractor || !$entry->isRenderableFile()) {
            return null;
        }

        $blob = $this->reader->readBlob($gitDir, BlobSha::fromString($entry->objectId));

        return null === $blob || $blob->isBinary() ? null : $extractor->extract($language, $blob->bytes);
    }

    private function extractorFor(?string $language): ?SymbolExtractor
    {
        foreach ($this->extractors as $extractor) {
            if (\in_array($language, $extractor->languages(), true)) {
                return $extractor;
            }
        }

        return null;
    }
}
