<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\BlobShaType;
use App\Doctrine\Type\CommitShaType;
use App\Doctrine\Type\RepoPathType;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\TenantId;
use App\Index\IndexSql;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One path of a commit, shared by the reviews of that commit. {@see IndexSql} writes the rows.
 * The key holds the SHA-256 of the path, because a btree entry of a long path exceeds the PostgreSQL limit.
 */
#[ORM\Entity(readOnly: true)]
class IndexedFile implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $repositoryId;

    #[ORM\Id]
    #[ORM\Column(type: CommitShaType::NAME)]
    private CommitSha $commitSha;

    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $pathSha256;

    #[ORM\Column(type: RepoPathType::NAME)]
    private RepoPath $path;

    #[ORM\Column(type: BlobShaType::NAME)]
    private BlobSha $blobSha;

    #[ORM\Column(length: 6)]
    private string $mode;

    /** Null for a submodule */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $sizeBytes;

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }
}
