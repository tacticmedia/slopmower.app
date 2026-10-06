<?php

declare(strict_types=1);

namespace App\Doctrine\Embeddable;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\UserId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine has no nullable embeddable, so every column is nullable and a null actor is an embeddable with null fields.
 */
#[ORM\Embeddable]
final class ActorEmbeddable
{
    #[ORM\Column(nullable: true, enumType: ActorKind::class)]
    private ?ActorKind $kind;

    #[ORM\Column(type: UserId::class, nullable: true)]
    private ?UserId $userId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $agentName;

    private function __construct(?Actor $actor)
    {
        $this->kind = $actor?->kind;
        $this->userId = $actor?->userId;
        $this->agentName = $actor?->agentName;
    }

    public static function of(?Actor $actor): self
    {
        return new self($actor);
    }

    /**
     * @throws \UnexpectedValueException For stored columns that break the actor rules, so the caller gets no invalid_input
     */
    public function toActor(): ?Actor
    {
        if (null === $this->kind) {
            return null;
        }

        try {
            return Actor::of($this->kind, $this->userId, $this->agentName);
        } catch (InvalidInput $error) {
            throw new \UnexpectedValueException("Corrupt actor columns. {$error->getMessage()}", 0, $error);
        }
    }
}
