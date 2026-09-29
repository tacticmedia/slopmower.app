<?php

declare(strict_types=1);

namespace App\Domain\Actor;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Identity\UserId;

/**
 * A user has a userId. An agent acts for a user and has the name of its API token. The system has neither.
 */
final readonly class Actor
{
    private const string AGENT_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._@():-]*$/D';
    private const int AGENT_NAME_MAX_LENGTH = 64;

    private function __construct(
        public ActorKind $kind,
        public ?UserId $userId,
        public ?string $agentName,
    ) {
    }

    public static function user(UserId $userId): self
    {
        return new self(ActorKind::User, $userId, null);
    }

    public static function agent(UserId $userId, string $agentName): self
    {
        return self::fromParts(ActorKind::Agent, $userId, $agentName);
    }

    public static function system(): self
    {
        return new self(ActorKind::System, null, null);
    }

    /**
     * Rebuilds a stored actor and checks the rules of its kind.
     */
    public static function fromParts(ActorKind $kind, ?UserId $userId, ?string $agentName, string $field = 'actor'): self
    {
        $violations = [];
        if ((ActorKind::System === $kind) !== (null === $userId)) {
            $violations[] = new Violation($field.'.userId', ActorKind::System === $kind ? 'must be null for the system' : 'is required for a user or an agent');
        }
        if (ActorKind::Agent === $kind) {
            if (null === $agentName || \strlen($agentName) > self::AGENT_NAME_MAX_LENGTH || 1 !== preg_match(self::AGENT_NAME_PATTERN, $agentName)) {
                $violations[] = new Violation($field.'.agentName', 'must be an API token name of 1 to 64 characters');
            }
        } elseif (null !== $agentName) {
            $violations[] = new Violation($field.'.agentName', 'must be null for a user or the system');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($kind, $userId, $agentName);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->agentName === $other->agentName
            && (null === $this->userId ? null === $other->userId : null !== $other->userId && $this->userId->equals($other->userId));
    }
}
