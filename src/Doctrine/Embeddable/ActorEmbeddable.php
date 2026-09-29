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
 * An actor that is always set. {@see NullableActorEmbeddable} holds an optional one.
 */
#[ORM\Embeddable]
final class ActorEmbeddable
{
    #[ORM\Column(length: 16, enumType: ActorKind::class)]
    private ActorKind $kind;

    #[ORM\Column(type: UlidType::NAME, nullable: true)]
    private ?Ulid $userId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $agentName;

    private function __construct(Actor $actor)
    {
        $this->kind = $actor->kind;
        $this->userId = $actor->userId?->toUlid();
        $this->agentName = $actor->agentName;
    }

    public static function of(Actor $actor): self
    {
        return new self($actor);
    }

    public function toActor(): Actor
    {
        return Actor::fromParts($this->kind, null === $this->userId ? null : UserId::fromUlid($this->userId), $this->agentName);
    }
}
