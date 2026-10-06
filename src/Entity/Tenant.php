<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\TenantId;
use App\Repository\TenantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: TenantId::class)]
    private TenantId $id;

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
        $this->id = $id;
        $this->createdAt = $createdAt;
    }

    public function getId(): TenantId
    {
        return $this->id;
    }

    /**
     * Keeps the first request time.
     */
    public function requestDeletion(\DateTimeImmutable $now): void
    {
        $this->deletionRequestedAt ??= $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
