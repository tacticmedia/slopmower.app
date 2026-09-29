<?php

declare(strict_types=1);

namespace App\Doctrine\Embeddable;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Identity\UserId;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Doctrine loads an embeddable with null columns as an instance with null fields, never as null.
 * So the entity always holds an instance, and a null kind means no actor.
 */
#[ORM\Embeddable]
final class NullableActorEmbeddable
{
    #[ORM\Column(length: 16, nullable: true, enumType: ActorKind::class)]
    private ?ActorKind $kind;

    #[ORM\Column(type: UlidType::NAME, nullable: true)]
    private ?Ulid $userId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $agentName;

    private function __construct(?Actor $actor)
    {
        $this->kind = $actor?->kind;
        $this->userId = $actor?->userId?->toUlid();
        $this->agentName = $actor?->agentName;
    }

    public static function of(?Actor $actor): self
    {
        return new self($actor);
    }

    public function toActor(): ?Actor
    {
        if (null === $this->kind) {
            return null === $this->userId && null === $this->agentName
                ? null
                : throw new \LogicException('A stored actor has a user ID or an agent name without a kind.');
        }

        return Actor::fromParts($this->kind, null === $this->userId ? null : UserId::fromUlid($this->userId), $this->agentName);
    }
}
