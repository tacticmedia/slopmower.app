<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Index\IndexSql;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One path of a commit, shared by the reviews of that commit. {@see IndexSql} writes the rows.
 * The key holds the SHA-256 of the path, because a btree entry of a long path exceeds the PostgreSQL limit.
 */
#[ORM\Entity(readOnly: true)]
class IndexedFile implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: RepositoryId::class)]
    private RepositoryId $repositoryId;

    #[ORM\Id]
    #[ORM\Column(type: CommitSha::class)]
    private CommitSha $commitSha;

    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $pathSha256;

    #[ORM\Column(type: RepoPath::class)]
    private RepoPath $path;

    #[ORM\Column(type: BlobSha::class)]
    private BlobSha $blobSha;

    #[ORM\Column(length: 6)]
    private string $mode;

    /** Null for a submodule */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $sizeBytes;

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }
}
