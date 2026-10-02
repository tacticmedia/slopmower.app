<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\TreeEntry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Ulid;

/**
 * Two index jobs at one commit, or V9 and a job, write the same rows. A unique key violation in the ORM closes the
 * entity manager, so the writes skip conflicting rows instead.
 */
final class IndexSql
{
    private const string INSERT_FILES = 'INSERT INTO indexed_file (tenant_id, repository_id, commit_sha, path_sha256, path, blob_sha, mode, size_bytes) VALUES ';
    private const string INSERT_BLOB = 'INSERT INTO indexed_blob (tenant_id, repository_id, blob_sha, parse_error) VALUES (:tenantId, :repositoryId, :blobSha, :parseError) ON CONFLICT DO NOTHING';
    private const string INSERT_SYMBOLS = 'INSERT INTO symbol (tenant_id, repository_id, blob_sha, kind, fqn, name, parent_fqn, start_line, end_line, declaration_start_line, doc_start_line) VALUES ';
    private const string ON_CONFLICT = ' ON CONFLICT DO NOTHING';
    private const string SYMBOL_COUNTS = <<<'SQL'
        SELECT indexed.blob_sha, COUNT(stored.id) FROM indexed_blob indexed
        LEFT JOIN symbol stored ON stored.tenant_id = indexed.tenant_id AND stored.repository_id = indexed.repository_id AND stored.blob_sha = indexed.blob_sha
        WHERE indexed.tenant_id = :tenantId AND indexed.repository_id = :repositoryId AND indexed.blob_sha IN (
            SELECT listed.blob_sha FROM indexed_file listed WHERE listed.tenant_id = :tenantId AND listed.repository_id = :repositoryId AND listed.commit_sha = :commitSha
        )
        GROUP BY indexed.blob_sha
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
        foreach (array_chunk($entries, self::ROWS_PER_STATEMENT) as $chunk) {
            $rows = array_map(static fn (TreeEntry $entry): array => [
                self::uuid($tenantId), self::uuid($repositoryId), $commit->toString(), hash('sha256', $entry->path), $entry->path, $entry->objectId, $entry->mode, $entry->sizeBytes,
            ], $chunk);
            $this->insertRows(self::INSERT_FILES, $rows);
        }
    }

    /**
     * The blob row and its symbols commit together. A blob that another writer stored keeps its symbols.
     *
     * @return bool False when the blob has a row already
     */
    public function insertBlob(TenantId $tenantId, RepositoryId $repositoryId, BlobSha $blob, SymbolTable $table): bool
    {
        return $this->connection->transactional(function () use ($tenantId, $repositoryId, $blob, $table): bool {
            $inserted = $this->connection->executeStatement(self::INSERT_BLOB, [
                'tenantId' => self::uuid($tenantId),
                'repositoryId' => self::uuid($repositoryId),
                'blobSha' => $blob->toString(),
                'parseError' => $table->parseError,
            ], ['parseError' => Types::BOOLEAN]);

            if (0 === $inserted) {
                return false;
            }

            foreach (array_chunk($table->symbols, self::ROWS_PER_STATEMENT) as $chunk) {
                $rows = array_map(static fn (ExtractedSymbol $symbol): array => [
                    self::uuid($tenantId), self::uuid($repositoryId), $blob->toString(), $symbol->kind->value, $symbol->fqn, $symbol->name, $symbol->parentFqn,
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
        $rows = $this->connection->fetchAllNumeric(self::SYMBOL_COUNTS, ['tenantId' => self::uuid($tenantId), 'repositoryId' => self::uuid($repositoryId), 'commitSha' => $commit->toString()]);

        foreach ($rows as [$blobSha, $symbolCount]) {
            $counts[(string) $blobSha] = (int) $symbolCount;
        }

        return $counts;
    }

    /**
     * `app:index:rebuild --reparse`: every blob of the repository is parsed again.
     */
    public function deleteParsedBlobs(TenantId $tenantId, RepositoryId $repositoryId): void
    {
        $parameters = ['tenantId' => self::uuid($tenantId), 'repositoryId' => self::uuid($repositoryId)];

        $this->connection->transactional(function () use ($parameters): void {
            $this->connection->executeStatement(self::DELETE_SYMBOLS, $parameters);
            $this->connection->executeStatement(self::DELETE_BLOBS, $parameters);
        });
    }

    /**
     * @param non-empty-list<list<int|string|null>> $rows Positional values in the column order of $insertInto
     */
    private function insertRows(string $insertInto, array $rows): void
    {
        $placeholders = '('.implode(', ', array_fill(0, count($rows[0]), '?')).')';

        $this->connection->executeStatement($insertInto.implode(', ', array_fill(0, count($rows), $placeholders)).self::ON_CONFLICT, array_merge(...$rows));
    }

    /**
     * The ulid column type is a native uuid on PostgreSQL.
     */
    private static function uuid(\Stringable $id): string
    {
        return Ulid::fromString((string) $id)->toRfc4122();
    }
}
