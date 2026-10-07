<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Git\BlobSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Index\IndexSql;
use App\Repository\IndexedBlobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marks a parsed blob, also one without symbols, so it is parsed once. The symbol rows of the blob belong to `language`,
 * the {@see \App\Index\FileLanguage} of the path that was parsed first. {@see IndexSql} writes the rows.
 */
#[ORM\Entity(repositoryClass: IndexedBlobRepository::class, readOnly: true)]
class IndexedBlob implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: RepositoryId::class)]
    private RepositoryId $repositoryId;

    #[ORM\Id]
    #[ORM\Column(type: BlobSha::class)]
    private BlobSha $blobSha;

    #[ORM\Column(length: 32)]
    private string $language;

    #[ORM\Column]
    private bool $parseError;

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function hasParseError(): bool
    {
        return $this->parseError;
    }
}
