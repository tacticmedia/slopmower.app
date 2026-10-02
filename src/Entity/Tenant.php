<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\TenantId;
use App\Repository\TenantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The last event number of the tenant. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $eventSeq = 0;

    /** Set by A2. Only the sign-in lookups and the GitHub webhook consumer and jobs read it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

    public function __construct(TenantId $id, \DateTimeImmutable $createdAt)
    {
        $this->id = Ulid::fromString($id->toString());
        $this->createdAt = $createdAt;
    }

    public function getId(): TenantId
    {
        return TenantId::fromString($this->id->toBase32());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEventSeq(): int
    {
        return $this->eventSeq;
    }

    /**
     * Keeps the first request time.
     */
    public function requestDeletion(\DateTimeImmutable $now): void
    {
        $this->deletionRequestedAt ??= $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
