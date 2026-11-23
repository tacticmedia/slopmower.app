<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\TenantId;
use App\Domain\Issue\IssueLinkKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A link of two issues: one row per pair, in the order of the call, shown on both issues (D116). With the kind BlockedBy,
 * $issue waits for $target (D126). {@see \App\Application\IssueCommands::link()} replaces the row of the pair in either order.
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

    // The default gives a data dump from before the column its kind.
    #[ORM\Column(enumType: IssueLinkKind::class, options: ['default' => 'relates'])]
    private IssueLinkKind $kind;

    #[ORM\Column(type: Types::TEXT)]
    private string $note;

    #[ORM\Embedded]
    private ActorEmbeddable $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Issue $issue, Issue $target, IssueLinkKind $kind, string $note, Actor $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->issue = $issue;
        $this->target = $target;
        $this->tenantId = $issue->getTenantId();
        $this->kind = $kind;
        $this->note = $note;
        $this->createdBy = ActorEmbeddable::of($createdBy);
        $this->createdAt = $createdAt;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getTarget(): Issue
    {
        return $this->target;
    }

    public function other(Issue $issue): Issue
    {
        return $this->issue === $issue ? $this->target : $this->issue;
    }

    /**
     * @return 'relates'|'blocked_by'|'blocks' The IssueLinkKind of common.schema.json, as $issue reads the link
     */
    public function kindFor(Issue $issue): string
    {
        return IssueLinkKind::BlockedBy === $this->kind && $this->target === $issue ? 'blocks' : $this->kind->value;
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
