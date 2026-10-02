<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\CommitShaType;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
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
 * The partial index lets a second GitHub connect find the existing row. Its predicate is in the form PostgreSQL
 * returns, so the schema comparison finds no change.
 *
 * The sync columns change only through the conditional updates of {@see RepositoryRepository}, which bypass the
 * identity map. Read them with {@see RepositoryRepository::findFresh()}.
 */
#[ORM\Entity(repositoryClass: RepositoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_repository_name', columns: ['tenant_id', 'source', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_repository_github_id', columns: ['tenant_id', 'github_repository_id'], options: ['where' => '(github_repository_id IS NOT NULL)'])]
class Repository implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Column(enumType: RepositorySource::class)]
    private RepositorySource $source;

    #[ORM\Column]
    private string $name;

    #[ORM\Column(nullable: true)]
    private ?string $localDirectory;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $githubRepositoryId;

    #[ORM\Column(nullable: true)]
    private ?string $defaultBranch = null;

    #[ORM\Column(type: CommitShaType::NAME, nullable: true)]
    private ?CommitSha $defaultBranchHead = null;

    #[ORM\Column(enumType: SyncState::class)]
    private SyncState $syncState = SyncState::Cloning;

    /** A queued fetch job has not started yet. */
    #[ORM\Column]
    private bool $fetchRequested = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(enumType: RepositoryAccess::class)]
    private RepositoryAccess $access = RepositoryAccess::Active;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(TenantId $tenantId, RepositoryName $name, ?string $localDirectory, ?int $githubRepositoryId, \DateTimeImmutable $createdAt)
    {
        $this->id = Ulid::fromString(RepositoryId::generate()->toString());
        $this->tenantId = Ulid::fromString($tenantId->toString());
        $this->source = $name->source();
        $this->name = $name->toString();
        $this->localDirectory = $localDirectory;
        $this->githubRepositoryId = $githubRepositoryId;
        $this->createdAt = $createdAt;
    }

    public static function local(TenantId $tenantId, RepositoryName $name, string $localDirectory, \DateTimeImmutable $createdAt): self
    {
        return RepositorySource::Local === $name->source()
            ? new self($tenantId, $name, $localDirectory, null, $createdAt)
            : throw new \LogicException('A local repository needs a local name.');
    }

    public static function gitHub(TenantId $tenantId, RepositoryName $name, int $githubRepositoryId, \DateTimeImmutable $createdAt): self
    {
        return RepositorySource::Github === $name->source()
            ? new self($tenantId, $name, null, $githubRepositoryId, $createdAt)
            : throw new \LogicException('A GitHub repository needs a GitHub name.');
    }

    public function getId(): RepositoryId
    {
        return RepositoryId::fromString($this->id->toBase32());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function getSource(): RepositorySource
    {
        return $this->source;
    }

    /**
     * @throws \UnexpectedValueException For a stored name that breaks the name rules, so the caller gets no invalid_input
     */
    public function getName(): RepositoryName
    {
        try {
            return RepositoryName::forSource($this->source, $this->name);
        } catch (InvalidInput $error) {
            throw new \UnexpectedValueException("Corrupt name column. {$error->getMessage()}", 0, $error);
        }
    }

    public function getLocalDirectory(): ?string
    {
        return $this->localDirectory;
    }

    public function getGithubRepositoryId(): ?int
    {
        return $this->githubRepositoryId;
    }

    public function getDefaultBranch(): ?string
    {
        return $this->defaultBranch;
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

    public function isFetchRequested(): bool
    {
        return $this->fetchRequested;
    }
}
