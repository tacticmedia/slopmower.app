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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

/**
 * GitHub logins can be renamed and taken again, so a GitHub user is identified by githubUserId.
 * The partial index predicate is in the form PostgreSQL returns, so the schema comparison finds no change.
 * The security identifier is the user ID. The session stores a serialized copy, so it must not hold review data.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\Index(name: 'idx_app_user_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_app_user_local_login', columns: ['login'], options: ['where' => "((sign_in_method)::text = 'local'::text)"])]
class User implements TenantOwned, UserInterface
{
    /** A session caller. API tokens authenticate an {@see \App\Security\AgentUser} instead. */
    public const string ROLE = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Column(enumType: SignInMethod::class)]
    private SignInMethod $signInMethod;

    #[ORM\Column(type: Types::BIGINT, unique: true, nullable: true)]
    private ?int $githubUserId;

    #[ORM\Column]
    private string $login;

    #[ORM\Column(nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $avatarUrl;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSignInAt;

    private function __construct(
        TenantId $tenantId,
        SignInMethod $signInMethod,
        ?int $githubUserId,
        string $login,
        ?string $name,
        ?string $avatarUrl,
        \DateTimeImmutable $signedInAt,
    ) {
        $this->id = Ulid::fromString(UserId::generate()->toString());
        $this->tenantId = Ulid::fromString($tenantId->toString());
        $this->signInMethod = $signInMethod;
        $this->githubUserId = $githubUserId;
        $this->login = $login;
        $this->name = $name;
        $this->avatarUrl = $avatarUrl;
        $this->createdAt = $signedInAt;
        $this->lastSignInAt = $signedInAt;
    }

    public static function local(TenantId $tenantId, string $login, \DateTimeImmutable $signedInAt): self
    {
        return new self($tenantId, SignInMethod::Local, null, $login, null, null, $signedInAt);
    }

    public static function gitHub(
        TenantId $tenantId,
        int $githubUserId,
        string $login,
        ?string $name,
        ?string $avatarUrl,
        \DateTimeImmutable $signedInAt,
    ): self {
        return new self($tenantId, SignInMethod::GitHub, $githubUserId, $login, $name, $avatarUrl, $signedInAt);
    }

    public function recordSignIn(\DateTimeImmutable $signedInAt): void
    {
        $this->lastSignInAt = $signedInAt;
    }

    public function recordGitHubSignIn(string $login, ?string $name, ?string $avatarUrl, \DateTimeImmutable $signedInAt): void
    {
        $this->login = $login;
        $this->name = $name;
        $this->avatarUrl = $avatarUrl;
        $this->lastSignInAt = $signedInAt;
    }

    public function getId(): UserId
    {
        return UserId::fromString($this->id->toBase32());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
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

    public function getUserIdentifier(): string
    {
        $id = $this->getId()->toString();
        \assert('' !== $id);

        return $id;
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }
}
