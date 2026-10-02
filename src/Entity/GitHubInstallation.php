<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\TenantId;
use App\GitHub\GitHubAccountType;
use App\Repository\GitHubInstallationRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * An installation of the GitHub App that the user could access at the last sign-in. A repository keeps the installation
 * ID without a foreign key, so deleting the installation row leaves it valid.
 */
#[ORM\Entity(repositoryClass: GitHubInstallationRepository::class)]
#[ORM\Table(name: 'github_installation')]
#[ORM\UniqueConstraint(name: 'uniq_github_installation', columns: ['tenant_id', 'installation_id'])]
class GitHubInstallation implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

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
        $this->id = new Ulid();
        $this->tenantId = Ulid::fromString($tenantId->toString());
        $this->installationId = $installationId;
        $this->accountLogin = $accountLogin;
        $this->accountType = $accountType;
        $this->suspendedAt = $suspendedAt;
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
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
