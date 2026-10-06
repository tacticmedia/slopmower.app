<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Git\BlobSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Index\ExtractedSymbol;
use App\Index\IndexSql;
use App\Repository\SymbolRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One symbol of a parsed blob. {@see IndexSql} writes the rows.
 */
#[ORM\Entity(repositoryClass: SymbolRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_symbol_blob', columns: ['tenant_id', 'repository_id', 'blob_sha'])]
class Symbol implements TenantOwned
{
    public const int MAX_FQN_CHARACTERS = 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(type: RepositoryId::class)]
    private RepositoryId $repositoryId;

    #[ORM\Column(type: BlobSha::class)]
    private BlobSha $blobSha;

    #[ORM\Column(enumType: SymbolKind::class)]
    private SymbolKind $kind;

    #[ORM\Column(length: self::MAX_FQN_CHARACTERS)]
    private string $fqn;

    #[ORM\Column(length: self::MAX_FQN_CHARACTERS)]
    private string $name;

    #[ORM\Column(length: self::MAX_FQN_CHARACTERS, nullable: true)]
    private ?string $parentFqn;

    #[ORM\Column]
    private int $startLine;

    #[ORM\Column]
    private int $endLine;

    #[ORM\Column]
    private int $declarationStartLine;

    #[ORM\Column(nullable: true)]
    private ?int $docStartLine;

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function toExtracted(): ExtractedSymbol
    {
        return new ExtractedSymbol($this->kind, $this->fqn, $this->name, $this->parentFqn, LineRange::of($this->startLine, $this->endLine), $this->declarationStartLine, $this->docStartLine);
    }
}
