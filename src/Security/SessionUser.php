<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The user of a browser session. The session stores it, {@see TenantUserProvider} refreshes it on every request.
 */
#[Exclude]
final class SessionUser implements UserInterface
{
    public function __construct(
        private readonly UserId $userId,
        private readonly TenantId $tenantId,
        private readonly string $login,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getId(), $user->getTenantId(), $user->getLogin());
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        $identifier = $this->userId->toString();
        \assert('' !== $identifier);

        return $identifier;
    }
}
