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
    private const array PERMISSION_LEVELS = ['read' => 1, 'write' => 2, 'admin' => 3];

    #[ORM\Id]
    #[ORM\Column(type: GitHubInstallationId::class)]
    private GitHubInstallationId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $installationId;

    /** Null when no listing or webhook has given the account ID of the row. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $accountId;

    #[ORM\Column]
    private string $accountLogin;

    #[ORM\Column(enumType: GitHubAccountType::class)]
    private GitHubAccountType $accountType;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt;

    /** @var array<string, string> The accepted permissions as GitHub sends them, for example `['contents' => 'read']` */
    #[ORM\Column(type: Types::JSONB)]
    private array $permissions;

    /**
     * @param array<string, string> $permissions
     */
    public function __construct(TenantId $tenantId, int $installationId, int $accountId, string $accountLogin, GitHubAccountType $accountType, ?\DateTimeImmutable $suspendedAt, array $permissions)
    {
        $this->id = GitHubInstallationId::generate();
        $this->tenantId = $tenantId;
        $this->installationId = $installationId;
        $this->accountId = $accountId;
        $this->accountLogin = $accountLogin;
        $this->accountType = $accountType;
        $this->suspendedAt = $suspendedAt;
        $this->permissions = $permissions;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getInstallationId(): int
    {
        return $this->installationId;
    }

    public function getAccountId(): ?int
    {
        return $this->accountId;
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

    /**
     * @param array<string, string> $permissions
     */
    public function recordPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
    }

    /**
     * A higher level covers a lower one: `write` covers `read`.
     *
     * @param array<string, string> $permissions The permissions of a job, for example {@see \App\GitHub\InstallationTokenProvider::CHECKS_WRITE}
     */
    public function accepts(array $permissions): bool
    {
        return array_all(
            $permissions,
            fn (string $level, string $permission): bool => (self::PERMISSION_LEVELS[$this->permissions[$permission] ?? ''] ?? 0) >= (self::PERMISSION_LEVELS[$level] ?? \PHP_INT_MAX),
        );
    }
}
