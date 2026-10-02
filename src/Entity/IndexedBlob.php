<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\BlobShaType;
use App\Domain\Git\BlobSha;
use App\Domain\Identity\TenantId;
use App\Index\IndexSql;
use App\Repository\IndexedBlobRepository;
use App\Tenancy\TenantOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Marks a parsed blob, also one without symbols, so it is parsed once. {@see IndexSql} writes the rows.
 */
#[ORM\Entity(repositoryClass: IndexedBlobRepository::class, readOnly: true)]
class IndexedBlob implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $repositoryId;

    #[ORM\Id]
    #[ORM\Column(type: BlobShaType::NAME)]
    private BlobSha $blobSha;

    #[ORM\Column]
    private bool $parseError;

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function hasParseError(): bool
    {
        return $this->parseError;
    }
}
