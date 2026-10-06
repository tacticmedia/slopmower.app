<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\TreeEntry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Two index jobs at one commit, or V9 and a job, write the same rows. A unique key violation in the ORM closes the
 * entity manager, so the writes skip conflicting rows instead.
 *
 * Each insert locks the repository row FOR KEY SHARE, the weakest lock that {@see \App\Repository\DeletionSql} waits
 * for, so the sync state updates of the row do not wait. A write for a deleted repository, or one whose access was
 * removed, writes nothing.
 */
final class IndexSql
{
    private const string LOCK_REPOSITORY = "SELECT id FROM repository WHERE tenant_id = :tenantId AND id = :repositoryId AND access = 'active' FOR KEY SHARE";
    private const string INSERT_FILES = 'INSERT INTO indexed_file (tenant_id, repository_id, commit_sha, path_sha256, path, blob_sha, mode, size_bytes) VALUES ';
    private const string INSERT_BLOB = 'INSERT INTO indexed_blob (tenant_id, repository_id, blob_sha, parse_error) VALUES (:tenantId, :repositoryId, :blobSha, :parseError) ON CONFLICT DO NOTHING';
    private const string INSERT_SYMBOLS = 'INSERT INTO symbol (tenant_id, repository_id, blob_sha, kind, fqn, fqn_sha256, name, parent_fqn, start_line, end_line, declaration_start_line, doc_start_line) VALUES ';
    private const string ON_CONFLICT = ' ON CONFLICT DO NOTHING';
    private const string SYMBOL_COUNTS = <<<'SQL'
        SELECT indexed.blob_sha, COUNT(stored.id) FROM indexed_blob indexed
        LEFT JOIN symbol stored ON stored.tenant_id = indexed.tenant_id AND stored.repository_id = indexed.repository_id AND stored.blob_sha = indexed.blob_sha
        WHERE indexed.tenant_id = :tenantId AND indexed.repository_id = :repositoryId AND indexed.blob_sha IN (
            SELECT listed.blob_sha FROM indexed_file listed WHERE listed.tenant_id = :tenantId AND listed.repository_id = :repositoryId AND listed.commit_sha = :commitSha
        )
        GROUP BY indexed.blob_sha
        SQL;
    private const string DECLARATIONS = <<<'SQL'
        SELECT listed.path, stored.fqn, stored.kind, stored.start_line, stored.end_line, stored.declaration_start_line FROM symbol stored
        JOIN indexed_file listed ON listed.tenant_id = stored.tenant_id AND listed.repository_id = stored.repository_id AND listed.blob_sha = stored.blob_sha
        WHERE stored.tenant_id = :tenantId AND stored.repository_id = :repositoryId AND stored.fqn_sha256 IN (:fqnSha256s) AND stored.fqn IN (:fqns)
        AND listed.commit_sha = :commitSha
        SQL;
    private const string DELETE_SYMBOLS = 'DELETE FROM symbol WHERE tenant_id = :tenantId AND repository_id = :repositoryId';
    private const string DELETE_BLOBS = 'DELETE FROM indexed_blob WHERE tenant_id = :tenantId AND repository_id = :repositoryId';

    /** Keeps each statement far below the PostgreSQL limit of 65,535 parameters. */
    private const int ROWS_PER_STATEMENT = 1000;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<TreeEntry> $entries With paths that RepoPath accepts
     */
    public function insertFiles(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, array $entries): void
    {
        $this->connection->transactional(function () use ($tenantId, $repositoryId, $commit, $entries): void {
            if (!$this->lockRepository($tenantId, $repositoryId)) {
                return;
            }

            foreach (array_chunk($entries, self::ROWS_PER_STATEMENT) as $chunk) {
                $rows = array_map(static fn (TreeEntry $entry): array => [
                    $tenantId->toRfc4122(), $repositoryId->toRfc4122(), $commit->toString(), hash('sha256', $entry->path), $entry->path, $entry->objectId, $entry->mode, $entry->sizeBytes,
                ], $chunk);
                $this->insertRows(self::INSERT_FILES, $rows);
            }
        });
    }

    /**
     * The blob row and its symbols commit together. A blob that another writer stored keeps its symbols.
     *
     * @return bool False when the blob has a row already, or the repository is gone or removed
     */
    public function insertBlob(TenantId $tenantId, RepositoryId $repositoryId, BlobSha $blob, SymbolTable $table): bool
    {
        return $this->connection->transactional(function () use ($tenantId, $repositoryId, $blob, $table): bool {
            if (!$this->lockRepository($tenantId, $repositoryId)) {
                return false;
            }

            $inserted = $this->connection->executeStatement(self::INSERT_BLOB, [
                'tenantId' => $tenantId->toRfc4122(),
                'repositoryId' => $repositoryId->toRfc4122(),
                'blobSha' => $blob->toString(),
                'parseError' => $table->parseError,
            ], ['parseError' => Types::BOOLEAN]);

            if (0 === $inserted) {
                return false;
            }

            foreach (array_chunk($table->symbols, self::ROWS_PER_STATEMENT) as $chunk) {
                $rows = array_map(static fn (ExtractedSymbol $symbol): array => [
                    $tenantId->toRfc4122(), $repositoryId->toRfc4122(), $blob->toString(), $symbol->kind->value, $symbol->fqn, hash('sha256', $symbol->fqn), $symbol->name, $symbol->parentFqn,
                    $symbol->range->startLine, $symbol->range->endLine, $symbol->declarationStartLine, $symbol->docStartLine,
                ], $chunk);
                $this->insertRows(self::INSERT_SYMBOLS, $rows);
            }

            return true;
        });
    }

    /**
     * @return array<string, int> Symbol count by blob SHA, for the blobs of the commit that have an indexed_blob row
     */
    public function storedSymbolCounts(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit): array
    {
        $counts = [];
        $rows = $this->connection->fetchAllNumeric(self::SYMBOL_COUNTS, ['tenantId' => $tenantId->toRfc4122(), 'repositoryId' => $repositoryId->toRfc4122(), 'commitSha' => $commit->toString()]);

        foreach ($rows as [$blobSha, $symbolCount]) {
            $counts[(string) $blobSha] = (int) $symbolCount;
        }

        return $counts;
    }

    /**
     * V14: the declarations of each reference among the symbols of the commit. A blob that the index job has not parsed
     * yet has no symbols.
     *
     * @param list<Reference> $references
     *
     * @return list<Declaration> In the order of {@see Declaration::compare()}
     */
    public function declarations(TenantId $tenantId, RepositoryId $repositoryId, CommitSha $commit, array $references): array
    {
        // PostgreSQL refuses a parameter that is not UTF-8, and no stored FQN is one.
        $fqns = array_values(array_filter(array_unique(array_merge(...array_map(static fn (Reference $reference): array => $reference->fqns, $references))), static fn (string $fqn): bool => mb_check_encoding($fqn, 'UTF-8')));

        if ([] === $fqns) {
            return [];
        }

        $byFqn = [];
        $rows = $this->connection->fetchAllAssociative(
            self::DECLARATIONS,
            [
                'tenantId' => $tenantId->toRfc4122(),
                'repositoryId' => $repositoryId->toRfc4122(),
                'fqnSha256s' => array_map(static fn (string $fqn): string => hash('sha256', $fqn), $fqns),
                'fqns' => $fqns,
                'commitSha' => $commit->toString(),
            ],
            ['fqnSha256s' => ArrayParameterType::STRING, 'fqns' => ArrayParameterType::STRING],
        );

        foreach ($rows as $row) {
            $fqn = (string) $row['fqn'];
            $range = LineRange::of((int) $row['start_line'], (int) $row['end_line']);
            $byFqn[$fqn][] = new Declaration(RepoPath::fromString((string) $row['path']), $fqn, SymbolKind::from((string) $row['kind']), $range, (int) $row['declaration_start_line']);
        }

        $declarations = [];

        foreach ($references as $reference) {
            foreach ($reference->fqns as $fqn) {
                $matching = array_filter($byFqn[$fqn] ?? [], static fn (Declaration $declaration): bool => in_array($declaration->kind, $reference->kinds, true));

                if ([] !== $matching) {
                    array_push($declarations, ...$matching);
                    break;
                }
            }
        }

        usort($declarations, Declaration::compare(...));

        return $declarations;
    }

    /**
     * `app:index:rebuild --reparse`: every blob of the repository is parsed again.
     */
    public function deleteParsedBlobs(TenantId $tenantId, RepositoryId $repositoryId): void
    {
        $parameters = ['tenantId' => $tenantId->toRfc4122(), 'repositoryId' => $repositoryId->toRfc4122()];

        $this->connection->transactional(function () use ($parameters): void {
            $this->connection->executeStatement(self::DELETE_SYMBOLS, $parameters);
            $this->connection->executeStatement(self::DELETE_BLOBS, $parameters);
        });
    }

    /**
     * @return bool False when the repository is gone or removed
     */
    private function lockRepository(TenantId $tenantId, RepositoryId $repositoryId): bool
    {
        return false !== $this->connection->fetchOne(self::LOCK_REPOSITORY, ['tenantId' => $tenantId->toRfc4122(), 'repositoryId' => $repositoryId->toRfc4122()]);
    }

    /**
     * @param non-empty-list<list<int|string|null>> $rows Positional values in the column order of $insertInto
     */
    private function insertRows(string $insertInto, array $rows): void
    {
        $placeholders = '('.implode(', ', array_fill(0, count($rows[0]), '?')).')';

        $this->connection->executeStatement($insertInto.implode(', ', array_fill(0, count($rows), $placeholders)).self::ON_CONFLICT, array_merge(...$rows));
    }
}
