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

    /** Not a foreign key: the installation row can go while the repository stays. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $githubInstallationId;

    #[ORM\Column(nullable: true)]
    private ?bool $githubPrivate;

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

    private function __construct(TenantId $tenantId, RepositoryName $name, \DateTimeImmutable $createdAt)
    {
        $this->id = Ulid::fromString(RepositoryId::generate()->toString());
        $this->tenantId = Ulid::fromString($tenantId->toString());
        $this->source = $name->source();
        $this->name = $name->toString();
        $this->localDirectory = null;
        $this->githubRepositoryId = null;
        $this->githubInstallationId = null;
        $this->githubPrivate = null;
        $this->createdAt = $createdAt;
    }

    public static function local(TenantId $tenantId, RepositoryName $name, string $localDirectory, \DateTimeImmutable $createdAt): self
    {
        if (RepositorySource::Local !== $name->source()) {
            throw new \LogicException('A local repository needs a local name.');
        }

        $repository = new self($tenantId, $name, $createdAt);
        $repository->localDirectory = $localDirectory;

        return $repository;
    }

    public static function gitHub(TenantId $tenantId, RepositoryName $name, int $githubRepositoryId, int $installationId, bool $private, \DateTimeImmutable $createdAt): self
    {
        if (RepositorySource::Github !== $name->source()) {
            throw new \LogicException('A GitHub repository needs a GitHub name.');
        }

        $repository = new self($tenantId, $name, $createdAt);
        $repository->githubRepositoryId = $githubRepositoryId;
        $repository->recordGitHubListing($name, $installationId, $private);

        return $repository;
    }

    /**
     * Each sign-in updates the name, the installation, and the visibility from the candidate list.
     */
    public function recordGitHubListing(RepositoryName $name, int $installationId, bool $private): void
    {
        if (RepositorySource::Github !== $this->source || RepositorySource::Github !== $name->source()) {
            throw new \LogicException('Only a GitHub repository takes a GitHub listing.');
        }

        $this->name = $name->toString();
        $this->githubInstallationId = $installationId;
        $this->githubPrivate = $private;
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

    public function getGithubInstallationId(): ?int
    {
        return $this->githubInstallationId;
    }

    public function isGithubPrivate(): ?bool
    {
        return $this->githubPrivate;
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
