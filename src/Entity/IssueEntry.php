<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\TenantId;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueEntryKind;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the thread of an issue. Append-only: it has no setter. The section_changed entries are the revision
 * history of a section, and the ID of the newest one of the plan is the plan revision (D116).
 */
#[ORM\Entity]
class IssueEntry implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: IssueEntryId::class)]
    private IssueEntryId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    #[ORM\Embedded]
    private ActorEmbeddable $actor;

    #[ORM\Column(enumType: IssueEntryKind::class)]
    private IssueEntryKind $kind;

    #[ORM\Column(nullable: true, enumType: CommentLabel::class)]
    private ?CommentLabel $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body;

    /** @var array<string, string|null> */
    #[ORM\Column(type: Types::JSONB)]
    private array $data;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, string|null> $data
     */
    private function __construct(Issue $issue, Actor $actor, IssueEntryKind $kind, ?string $body, array $data, \DateTimeImmutable $createdAt, ?CommentLabel $label = null)
    {
        $this->id = IssueEntryId::generate();
        $this->tenantId = $issue->getTenantId();
        $this->issue = $issue;
        $this->actor = ActorEmbeddable::of($actor);
        $this->kind = $kind;
        $this->label = $label;
        $this->body = $body;
        $this->data = $data;
        $this->createdAt = $createdAt;
    }

    public static function comment(Issue $issue, Actor $actor, string $body, CommentLabel $label, \DateTimeImmutable $createdAt): self
    {
        return new self($issue, $actor, IssueEntryKind::Comment, $body, [], $createdAt, $label);
    }

    public static function statusChanged(Issue $issue, Actor $actor, IssueStatus $from, IssueStatus $to, ?string $note, ?string $reviewer, \DateTimeImmutable $createdAt): self
    {
        return new self($issue, $actor, IssueEntryKind::StatusChanged, null, ['from' => $from->value, 'to' => $to->value, 'note' => $note, 'reviewer' => $reviewer], $createdAt);
    }

    /**
     * @param string $text The new text of the section
     */
    public static function sectionChanged(Issue $issue, Actor $actor, IssueSection $section, string $text, \DateTimeImmutable $createdAt): self
    {
        return new self($issue, $actor, IssueEntryKind::SectionChanged, $text, ['section' => $section->value], $createdAt);
    }

    /**
     * A user approves without a reviewer and a review note.
     */
    public static function planApproved(Issue $issue, Actor $actor, ?string $reviewer, ?string $reviewNote, IssueEntryId $planRevision, \DateTimeImmutable $createdAt): self
    {
        return new self($issue, $actor, IssueEntryKind::PlanApproved, null, ['reviewer' => $reviewer, 'reviewNote' => $reviewNote, 'planRevision' => $planRevision->toString()], $createdAt);
    }

    public function getId(): IssueEntryId
    {
        return $this->id;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getActor(): Actor
    {
        return $this->actor->toActor() ?? throw new \UnexpectedValueException('An issue entry has an actor.');
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getKind(): IssueEntryKind
    {
        return $this->kind;
    }

    /**
     * Null for an entry that is no comment. A comment with a null column reads as `comment`: a `make issues-dump` file
     * without the column restores to such rows.
     */
    public function getLabel(): ?CommentLabel
    {
        return IssueEntryKind::Comment === $this->kind ? $this->label ?? CommentLabel::Comment : null;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * `from`, `to`, `note`, `reviewer`, `section`, `reviewNote`, or `planRevision`. Null for a key of another kind.
     */
    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
