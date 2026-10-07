<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\GitHubInstallationId;
use App\Domain\Identity\TenantId;
use App\Repository\GitHubInstallationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An installation of the GitHub App that the user can access: listed at the last sign-in, then changed by the
 * installation webhooks.
 */
#[ORM\Entity(repositoryClass: GitHubInstallationRepository::class)]
#[ORM\Table(name: 'github_installation')]
#[ORM\UniqueConstraint(name: 'uniq_github_installation', columns: ['tenant_id', 'installation_id'])]
class GitHubInstallation implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: GitHubInstallationId::class)]
    private GitHubInstallationId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $installationId;

    #[ORM\Column]
    private string $accountLogin;

    #[ORM\Column(enumType: GitHubAccountType::class)]
    private GitHubAccountType $accountType;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt;

    public function __construct(TenantId $tenantId, int $installationId, string $accountLogin, GitHubAccountType $accountType, ?\DateTimeImmutable $suspendedAt)
    {
        $this->id = GitHubInstallationId::generate();
        $this->tenantId = $tenantId;
        $this->installationId = $installationId;
        $this->accountLogin = $accountLogin;
        $this->accountType = $accountType;
        $this->suspendedAt = $suspendedAt;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    /**
     * @param \DateTimeImmutable|null $suspendedAt Null for `unsuspend`
     */
    public function recordSuspension(?\DateTimeImmutable $suspendedAt): void
    {
        $this->suspendedAt = $suspendedAt;
    }
}
