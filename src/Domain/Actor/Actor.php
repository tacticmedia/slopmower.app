<?php

declare(strict_types=1);

namespace App\Domain\Actor;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\UserId;

final readonly class Actor
{
    private const int MAX_AGENT_NAME_CHARACTERS = 64;

    private function __construct(
        public ActorKind $kind,
        public ?UserId $userId,
        public ?string $agentName,
    ) {
    }

    public static function user(UserId $userId): self
    {
        return self::of(ActorKind::User, $userId, null);
    }

    /**
     * @param string $agentName The name of the API token
     */
    public static function agent(UserId $userId, string $agentName): self
    {
        return self::of(ActorKind::Agent, $userId, $agentName);
    }

    public static function system(): self
    {
        return self::of(ActorKind::System, null, null);
    }

    public static function of(ActorKind $kind, ?UserId $userId, ?string $agentName): self
    {
        $violations = [];
        $isSystem = ActorKind::System === $kind;
        $isAgent = ActorKind::Agent === $kind;

        if (!$isSystem && null === $userId) {
            $violations[] = ['field' => 'userId', 'message' => "Is required for a {$kind->value}."];
        } elseif ($isSystem && null !== $userId) {
            $violations[] = ['field' => 'userId', 'message' => 'Must be null for the system.'];
        }

        if ($isAgent && (null === $agentName || '' === $agentName || mb_strlen($agentName, 'UTF-8') > self::MAX_AGENT_NAME_CHARACTERS)) {
            $violations[] = ['field' => 'agentName', 'message' => 'Must be 1 to 64 characters for an agent.'];
        } elseif (!$isAgent && null !== $agentName) {
            $violations[] = ['field' => 'agentName', 'message' => "Must be null for a {$kind->value}."];
        }

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        return new self($kind, $userId, $agentName);
    }

    /**
     * Tokens of one user with the same name act as the same agent.
     */
    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && (null === $this->userId ? null === $other->userId : null !== $other->userId && $this->userId->equals($other->userId))
            && $this->agentName === $other->agentName;
    }
}
