<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\IssueId;
use App\Domain\Identity\TenantId;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * State-stored, not event data (D116). Each write locks the row and appends its {@see IssueEntry} in one transaction.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_issue_number', columns: ['project_id', 'number'])]
class Issue implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: IssueId::class)]
    private IssueId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(enumType: IssueStatus::class)]
    private IssueStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $problem = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $plan = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $followUps = null;

    /** The plan_approved entry of the plan as it is. SET NULL, so a purge deletes the entries before the issues. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?IssueEntry $planApproval = null;

    #[ORM\Embedded]
    private ActorEmbeddable $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Project $project, int $number, string $title, IssueStatus $status, Actor $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->id = IssueId::generate();
        $this->tenantId = $project->getTenantId();
        $this->project = $project;
        $this->number = $number;
        $this->title = $title;
        $this->status = $status;
        $this->createdBy = ActorEmbeddable::of($createdBy);
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): IssueId
    {
        return $this->id;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getKey(): IssueKey
    {
        return new IssueKey($this->project->getKey(), $this->number);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): IssueStatus
    {
        return $this->status;
    }

    public function getSection(IssueSection $section): ?string
    {
        return match ($section) {
            IssueSection::Problem => $this->problem,
            IssueSection::Plan => $this->plan,
            IssueSection::Result => $this->result,
            IssueSection::FollowUps => $this->followUps,
        };
    }

    public function getPlanApproval(): ?IssueEntry
    {
        return $this->planApproval;
    }

    public function getCreatedBy(): Actor
    {
        return $this->createdBy->toActor() ?? throw new \UnexpectedValueException('An issue has a creator.');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $title, \DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->updatedAt = $now;
    }

    /**
     * A plan with new text has no approval.
     */
    public function changeSection(IssueSection $section, string $text, \DateTimeImmutable $now): void
    {
        match ($section) {
            IssueSection::Problem => $this->problem = $text,
            IssueSection::Plan => $this->plan = $text,
            IssueSection::Result => $this->result = $text,
            IssueSection::FollowUps => $this->followUps = $text,
        };

        if (IssueSection::Plan === $section) {
            $this->planApproval = null;
        }

        $this->updatedAt = $now;
    }

    public function setStatus(IssueStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function approvePlan(IssueEntry $approval): void
    {
        $this->planApproval = $approval;
        $this->updatedAt = $approval->getCreatedAt();
    }

    /**
     * For a comment or a link, which change no column of the issue.
     */
    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
