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
        $criteria = ['repositoryId' => $repository->getId(), 'blobSha' => BlobSha::fromString($entry->objectId)];
        $indexed = $this->supports(RepoPath::fromString($entry->path)) && $entry->isRenderableFile() ? $this->indexedBlobs->findOneBy($criteria) : null;

        if (!$indexed instanceof IndexedBlob) {
            return $this->parse($repository, $gitDir, $entry);
        }

        $symbols = array_map(static fn (Symbol $symbol): ExtractedSymbol => $symbol->toExtracted(), $this->symbols->findBy($criteria));

        return new SymbolTable($this->extractor->language(), $indexed->hasParseError(), $symbols);
    }

    /**
     * Parses and stores without a lookup, for the index job, which skips the blobs that have an IndexedBlob row.
     *
     * @param string    $gitDir The clone of $repository
     * @param TreeEntry $entry  A blob or submodule entry whose path RepoPath accepts
     *
     * @throws GitFailed
     */
    public function parse(Repository $repository, string $gitDir, TreeEntry $entry): SymbolTable
    {
        if (!$this->supports(RepoPath::fromString($entry->path))) {
            return SymbolTable::unsupported();
        }

        $noSymbols = new SymbolTable($this->extractor->language(), false, []);
        $blobSha = BlobSha::fromString($entry->objectId);
        $blob = $entry->isRenderableFile() ? $this->reader->readBlob($gitDir, $blobSha) : null;

        if (null === $blob || $blob->isBinary()) {
            return $noSymbols;
        }

        $table = $this->extractor->extract($blob->bytes);
        $this->indexSql->insertBlob($repository->getTenantId(), $repository->getId(), $blobSha, $table);

        return $table;
    }
}
