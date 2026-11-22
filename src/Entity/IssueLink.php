<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A link of two issues without a direction: one row per pair, in the order of the call, shown on both issues (D116).
 * {@see \App\Application\IssueCommands::link()} refuses the pair in the other order.
 */
#[ORM\Entity]
class IssueLink implements TenantOwned
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    private Issue $issue;

    #[ORM\Id]
    #[ORM\ManyToOne]
    private Issue $target;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $note;

    #[ORM\Embedded]
    private ActorEmbeddable $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Issue $issue, Issue $target, string $note, Actor $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->issue = $issue;
        $this->target = $target;
        $this->tenantId = $issue->getTenantId();
        $this->note = $note;
        $this->createdBy = ActorEmbeddable::of($createdBy);
        $this->createdAt = $createdAt;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function other(Issue $issue): Issue
    {
        return $this->issue === $issue ? $this->target : $this->issue;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getCreatedBy(): Actor
    {
        return $this->createdBy->toActor() ?? throw new \UnexpectedValueException('An issue link has a creator.');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
