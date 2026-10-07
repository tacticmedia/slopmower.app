<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Identity\ViewedFileId;
use App\Entity\ViewedFile;
use Doctrine\DBAL\Connection;

/**
 * Idempotent writes of the "Viewed" marks, also under concurrent calls.
 */
final class ViewedFileSql
{
    private const string MARK = 'INSERT INTO viewed_file (id, tenant_id, user_id, review_id, path, path_hash) VALUES (:id, :tenantId, :userId, :reviewId, :path, :pathHash)'
        .' ON CONFLICT (review_id, user_id, path_hash) DO NOTHING';
    private const string UNMARK = 'DELETE FROM viewed_file WHERE tenant_id = :tenantId AND review_id = :reviewId AND user_id = :userId AND path_hash = :pathHash';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function mark(TenantId $tenantId, UserId $userId, ReviewId $reviewId, RepoPath $path): void
    {
        $this->connection->executeStatement(self::MARK, [
            'id' => ViewedFileId::generate()->toRfc4122(),
            'path' => $path->toString(),
        ] + self::key($tenantId, $userId, $reviewId, $path));
    }

    public function unmark(TenantId $tenantId, UserId $userId, ReviewId $reviewId, RepoPath $path): void
    {
        $this->connection->executeStatement(self::UNMARK, self::key($tenantId, $userId, $reviewId, $path));
    }

    /**
     * @return array{tenantId: string, userId: string, reviewId: string, pathHash: string}
     */
    private static function key(TenantId $tenantId, UserId $userId, ReviewId $reviewId, RepoPath $path): array
    {
        return [
            'tenantId' => $tenantId->toRfc4122(),
            'userId' => $userId->toRfc4122(),
            'reviewId' => $reviewId->toRfc4122(),
            'pathHash' => ViewedFile::hash($path),
        ];
    }
}
