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

/**
 * The one parse-on-demand path. Only a regular, non-binary file of at most 1 MiB has symbols.
 */
final class BlobSymbols
{
    public function __construct(
        private readonly PhpSymbolExtractor $extractor,
        private readonly IndexedBlobRepository $indexedBlobs,
        private readonly SymbolRepository $symbols,
        private readonly IndexSql $indexSql,
        private readonly GitReader $reader,
    ) {
    }

    public function supports(RepoPath $path): bool
    {
        return $this->extractor->supports($path);
    }

    /**
     * The stored symbols of the blob, or parses it and stores the result.
     *
     * @param string    $gitDir The clone of $repository
     * @param TreeEntry $entry  A blob entry whose path RepoPath accepts
     *
     * @throws GitFailed
     */
    public function of(Repository $repository, string $gitDir, TreeEntry $entry): SymbolTable
    {
        if (!$this->supports(RepoPath::fromString($entry->path))) {
            return SymbolTable::unsupported();
        }

        $criteria = ['repositoryId' => $repository->getId(), 'blobSha' => BlobSha::fromString($entry->objectId)];
        $indexed = $entry->isRenderableFile() ? $this->indexedBlobs->findOneBy($criteria) : null;

        if ($indexed instanceof IndexedBlob) {
            $symbols = array_map(static fn (Symbol $symbol): ExtractedSymbol => $symbol->toExtracted(), $this->symbols->findBy($criteria));

            return new SymbolTable($this->extractor->language(), $indexed->hasParseError(), $symbols);
        }

        $table = $this->parse($gitDir, $entry);

        if (null === $table) {
            return new SymbolTable($this->extractor->language(), false, []);
        }

        $this->indexSql->insertBlobs($repository->getTenantId(), $repository->getId(), [$entry->objectId => $table]);

        return $table;
    }

    /**
     * Parses without a lookup and without a write, for the index job, which skips the blobs that have an IndexedBlob
     * row and writes the others in batches.
     *
     * @param TreeEntry $entry A blob or submodule entry whose path the extractor supports
     *
     * @return SymbolTable|null Null for an entry that gets no IndexedBlob row: not a renderable file, or binary
     *
     * @throws GitFailed
     */
    public function parse(string $gitDir, TreeEntry $entry): ?SymbolTable
    {
        $blob = $entry->isRenderableFile() ? $this->reader->readBlob($gitDir, BlobSha::fromString($entry->objectId)) : null;

        return null === $blob || $blob->isBinary() ? null : $this->extractor->extract($blob->bytes);
    }
}
