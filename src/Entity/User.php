<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\User\SignInMethod;
use App\Repository\UserRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * GitHub logins can be renamed and taken again, so a GitHub user is identified by `githubUserId`.
 * The `where` predicate is in the form that PostgreSQL returns, so the schema comparison finds no difference.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\Index(name: 'idx_app_user_tenant_id', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(
    name: 'uniq_app_user_local_login',
    columns: ['login'],
    options: ['where' => "((sign_in_method)::text = 'local'::text)"],
)]
class User implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Column(length: 16, enumType: SignInMethod::class)]
    private SignInMethod $signInMethod;

    #[ORM\Column(type: Types::BIGINT, unique: true, nullable: true)]
    private ?int $githubUserId;

    #[ORM\Column]
    private string $login;

    #[ORM\Column(nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $avatarUrl;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSignInAt;

    private function __construct(
        UserId $id,
        TenantId $tenantId,
        SignInMethod $signInMethod,
        ?int $githubUserId,
        string $login,
        ?string $name,
        ?string $avatarUrl,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id->toUlid();
        $this->tenantId = $tenantId->toUlid();
        $this->signInMethod = $signInMethod;
        $this->githubUserId = $githubUserId;
        $this->login = $login;
        $this->name = $name;
        $this->avatarUrl = $avatarUrl;
        $this->createdAt = $createdAt;
        $this->lastSignInAt = $createdAt;
    }

    public static function gitHub(
        UserId $id,
        TenantId $tenantId,
        int $githubUserId,
        string $login,
        ?string $name,
        ?string $avatarUrl,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $tenantId, SignInMethod::GitHub, $githubUserId, $login, $name, $avatarUrl, $createdAt);
    }

    public static function local(UserId $id, TenantId $tenantId, string $login, \DateTimeImmutable $createdAt): self
    {
        return new self($id, $tenantId, SignInMethod::Local, null, $login, null, null, $createdAt);
    }

    public function getId(): UserId
    {
        return UserId::fromUlid($this->id);
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromUlid($this->tenantId);
    }

    public function getSignInMethod(): SignInMethod
    {
        return $this->signInMethod;
    }

    public function getGithubUserId(): ?int
    {
        return $this->githubUserId;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSignInAt(): \DateTimeImmutable
    {
        return $this->lastSignInAt;
    }

    public function recordSignIn(\DateTimeImmutable $signedInAt): void
    {
        $this->lastSignInAt = $signedInAt;
    }
}
