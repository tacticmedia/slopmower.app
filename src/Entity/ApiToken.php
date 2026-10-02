<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\ApiToken\ApiTokenState;
use App\Domain\Identity\ApiTokenId;
use App\Domain\Identity\TenantId;
use App\Repository\ApiTokenRepository;
use App\Security\AccessScope;
use App\Tenancy\TenantOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Stores the SHA-256 hex digest of the secret, never the secret. A revoked row stays, so its events keep the agent name.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Index(name: 'idx_api_token_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_api_token_hash', columns: ['token_hash'])]
class ApiToken implements TenantOwned
{
    public const int LAST_USED_RESOLUTION_SECONDS = 60;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /** The agent name on every event made with this token. */
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 9)]
    private string $prefix;

    /** Null: all repositories of the tenant. */
    #[ORM\ManyToOne]
    private ?Repository $repository;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        User $user,
        string $name,
        ?Repository $repository,
        #[\SensitiveParameter] ApiTokenSecret $secret,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $expiresAt,
    ) {
        if (null !== $repository && !$repository->getTenantId()->equals($user->getTenantId())) {
            throw new \LogicException('The repository belongs to another tenant.');
        }

        $this->id = Ulid::fromString(ApiTokenId::generate()->toString());
        $this->tenantId = Ulid::fromString($user->getTenantId()->toString());
        $this->user = $user;
        $this->name = $name;
        $this->tokenHash = $secret->hash();
        $this->prefix = $secret->prefix();
        $this->repository = $repository;
        $this->createdAt = self::utc($createdAt);
        $this->expiresAt = null === $expiresAt ? null : self::utc($expiresAt);
    }

    public function state(\DateTimeImmutable $now): ApiTokenState
    {
        return match (true) {
            null !== $this->revokedAt => ApiTokenState::Revoked,
            null !== $this->expiresAt && $this->expiresAt <= $now => ApiTokenState::Expired,
            default => ApiTokenState::Active,
        };
    }

    /**
     * @return bool False within {@see self::LAST_USED_RESOLUTION_SECONDS} of the last recorded use
     */
    public function recordUse(\DateTimeImmutable $now): bool
    {
        if (null !== $this->lastUsedAt && $now < $this->lastUsedAt->modify('+'.self::LAST_USED_RESOLUTION_SECONDS.' seconds')) {
            return false;
        }

        $this->lastUsedAt = self::utc($now);

        return true;
    }

    /**
     * Keeps the first revocation time.
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= self::utc($now);
    }

    public function getId(): ApiTokenId
    {
        return ApiTokenId::fromString($this->id->toBase32());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getRepository(): ?Repository
    {
        return $this->repository;
    }

    public function getScope(): AccessScope
    {
        return null === $this->repository ? AccessScope::allRepositories() : AccessScope::repository($this->repository->getId());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * The column type stores the wall time without the offset.
     */
    private static function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
