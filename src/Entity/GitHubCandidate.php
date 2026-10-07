<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\GitHubCandidateId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Repository\GitHubCandidateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A repository that the user can connect: listed at the last sign-in, then changed by the installation webhooks (R2).
 */
#[ORM\Entity(repositoryClass: GitHubCandidateRepository::class)]
#[ORM\Table(name: 'github_candidate')]
#[ORM\UniqueConstraint(name: 'uniq_github_candidate', columns: ['tenant_id', 'github_repository_id'])]
class GitHubCandidate implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: GitHubCandidateId::class)]
    private GitHubCandidateId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $githubRepositoryId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $installationId;

    /** The account that the App is installed on. */
    #[ORM\Column]
    private string $accountLogin;

    #[ORM\Column]
    private string $owner;

    #[ORM\Column]
    private string $name;

    #[ORM\Column]
    private bool $private;

    #[ORM\Column]
    private string $defaultBranch;

    #[ORM\Column(type: Types::TEXT)]
    private string $htmlUrl;

    #[ORM\Column]
    private \DateTimeImmutable $listedAt;

    public function __construct(
        TenantId $tenantId,
        int $githubRepositoryId,
        int $installationId,
        string $accountLogin,
        RepositoryName $fullName,
        bool $private,
        string $defaultBranch,
        string $htmlUrl,
        \DateTimeImmutable $listedAt,
    ) {
        $this->id = GitHubCandidateId::generate();
        $this->tenantId = $tenantId;
        $this->githubRepositoryId = $githubRepositoryId;
        $this->installationId = $installationId;
        $this->accountLogin = $accountLogin;
        [$this->owner, $this->name] = explode('/', $fullName->toString(), 2);
        $this->private = $private;
        $this->defaultBranch = $defaultBranch;
        $this->htmlUrl = $htmlUrl;
        $this->listedAt = $listedAt;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getGithubRepositoryId(): int
    {
        return $this->githubRepositoryId;
    }

    public function getInstallationId(): int
    {
        return $this->installationId;
    }

    public function getAccountLogin(): string
    {
        return $this->accountLogin;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getFullName(): RepositoryName
    {
        return RepositoryName::github("{$this->owner}/{$this->name}");
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function getDefaultBranch(): string
    {
        return $this->defaultBranch;
    }

    public function getHtmlUrl(): string
    {
        return $this->htmlUrl;
    }

    public function getListedAt(): \DateTimeImmutable
    {
        return $this->listedAt;
    }
}
