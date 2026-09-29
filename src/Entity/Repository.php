<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\CommitShaType;
use App\Doctrine\WholeSeconds;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\RepositorySource;
use App\Domain\Repository\SyncState;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The partial unique index lets a second GitHub connect find the existing row.
 * Its `where` predicate is in the form that PostgreSQL returns, so the schema comparison finds no difference.
 */
#[ORM\Entity(repositoryClass: RepositoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_repository_tenant_source_name', columns: ['tenant_id', 'source', 'name'])]
#[ORM\UniqueConstraint(
    name: 'uniq_repository_tenant_github_repository_id',
    columns: ['tenant_id', 'github_repository_id'],
    options: ['where' => '(github_repository_id IS NOT NULL)'],
)]
class Repository implements TenantOwned
{
    private const int MAX_LOCAL_DIRECTORY_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Column(length: 16, enumType: RepositorySource::class)]
    private RepositorySource $source;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $localDirectory;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $githubRepositoryId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $defaultBranch = null;

    #[ORM\Column(type: CommitShaType::NAME, nullable: true)]
    private ?CommitSha $defaultBranchHead = null;

    #[ORM\Column(length: 16, enumType: SyncState::class)]
    private SyncState $syncState = SyncState::Cloning;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 16, enumType: RepositoryAccess::class)]
    private RepositoryAccess $access = RepositoryAccess::Active;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        RepositoryId $id,
        TenantId $tenantId,
        RepositoryName $name,
        ?string $localDirectory,
        ?int $githubRepositoryId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id->toUlid();
        $this->tenantId = $tenantId->toUlid();
        $this->source = $name->source();
        $this->name = $name->toString();
        $this->localDirectory = $localDirectory;
        $this->githubRepositoryId = $githubRepositoryId;
        $this->createdAt = WholeSeconds::require($createdAt, 'createdAt');
    }

    public static function gitHub(RepositoryId $id, TenantId $tenantId, RepositoryName $name, int $githubRepositoryId, \DateTimeImmutable $createdAt): self
    {
        if (RepositorySource::GitHub !== $name->source()) {
            throw new \LogicException('A GitHub repository needs an owner/name.');
        }

        return new self($id, $tenantId, $name, null, $githubRepositoryId, $createdAt);
    }

    public static function local(RepositoryId $id, TenantId $tenantId, RepositoryName $name, string $localDirectory, \DateTimeImmutable $createdAt): self
    {
        if (RepositorySource::Local !== $name->source()) {
            throw new \LogicException('A local repository needs a directory name.');
        }
        $localDirectoryLength = mb_strlen($localDirectory, 'UTF-8');
        if (0 === $localDirectoryLength || $localDirectoryLength > self::MAX_LOCAL_DIRECTORY_LENGTH) {
            throw InvalidInput::field('localDirectory', 'must have 1 to 255 characters');
        }

        return new self($id, $tenantId, $name, $localDirectory, null, $createdAt);
    }

    public function changeSync(SyncState $syncState, ?\DateTimeImmutable $lastFetchedAt, ?string $lastError): void
    {
        $this->syncState = $syncState;
        $this->lastFetchedAt = WholeSeconds::require($lastFetchedAt, 'lastFetchedAt');
        $this->lastError = $lastError;
    }

    public function changeDefaultBranch(?RefName $defaultBranch, ?CommitSha $defaultBranchHead): void
    {
        $this->defaultBranch = $defaultBranch?->toString();
        $this->defaultBranchHead = $defaultBranchHead;
    }

    public function removeAccess(): void
    {
        $this->access = RepositoryAccess::Removed;
        $this->syncState = SyncState::Unavailable;
    }

    public function getId(): RepositoryId
    {
        return RepositoryId::fromUlid($this->id);
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromUlid($this->tenantId);
    }

    public function getSource(): RepositorySource
    {
        return $this->source;
    }

    public function getName(): RepositoryName
    {
        return RepositoryName::fromSource($this->source, $this->name);
    }

    public function getLocalDirectory(): ?string
    {
        return $this->localDirectory;
    }

    public function getGithubRepositoryId(): ?int
    {
        return $this->githubRepositoryId;
    }

    public function getDefaultBranch(): ?RefName
    {
        return null === $this->defaultBranch ? null : RefName::fromString($this->defaultBranch, 'defaultBranch');
    }

    public function getDefaultBranchHead(): ?CommitSha
    {
        return $this->defaultBranchHead;
    }

    public function getSyncState(): SyncState
    {
        return $this->syncState;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getAccess(): RepositoryAccess
    {
        return $this->access;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
