<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Embeddable\IssuePartyEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\IssueId;
use App\Domain\Identity\TenantId;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueClaim;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueParty;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueStatus;
use App\Domain\Issue\WaitingFor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * State-stored, not event data (D116). Each write locks the row and appends its {@see IssueEntry} in one transaction.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_issue_number', columns: ['project_id', 'number'])]
#[ORM\Index(name: 'idx_issue_rank', columns: ['project_id', 'category', 'rank'])]
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

    /** One sequence per project, the lowest first (D137). */
    #[ORM\Column]
    private int $rank;

    #[ORM\Column(length: 200)]
    private string $title;

    // The database default fills the column when a data-only dump from before it is restored.
    #[ORM\Column(enumType: IssueCategory::class, options: ['default' => 'change'])]
    private IssueCategory $category;

    #[ORM\Column(enumType: IssueStatus::class)]
    private IssueStatus $status;

    /** Counts only in a by_issue project. Never ByIssue. */
    #[ORM\Column(nullable: true, enumType: ApprovalMode::class)]
    private ?ApprovalMode $approval = null;

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

    /** The holder of the claim (D130). Null columns: no claim. */
    #[ORM\Embedded]
    private ActorEmbeddable $claimHolder;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $claimSession = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimSince = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimUntil = null;

    /** No foreign key, as of an actor (D134). */
    #[ORM\Embedded(columnPrefix: 'requester_')]
    private IssuePartyEmbeddable $requester;

    #[ORM\Embedded(columnPrefix: 'assignee_')]
    private IssuePartyEmbeddable $assignee;

    /** Null in a status where nobody has the next step. */
    #[ORM\Column(nullable: true, enumType: WaitingFor::class)]
    private ?WaitingFor $waitingFor;

    #[ORM\Embedded]
    private ActorEmbeddable $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** The newest thread entry after the creation (D137). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    public function __construct(Project $project, int $number, int $rank, string $title, IssueCategory $category, IssueStatus $status, IssueParty $requester, IssueParty $assignee, Actor $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->id = IssueId::generate();
        $this->tenantId = $project->getTenantId();
        $this->project = $project;
        $this->number = $number;
        $this->rank = $rank;
        $this->title = $title;
        $this->category = $category;
        $this->status = $status;
        $this->requester = IssuePartyEmbeddable::of($requester);
        $this->assignee = IssuePartyEmbeddable::of($assignee);
        $this->waitingFor = WaitingFor::defaultFor($status);
        $this->claimHolder = ActorEmbeddable::of(null);
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

    public function getRank(): int
    {
        return $this->rank;
    }

    /**
     * Sets no updatedAt: a move is no change of the issue (D137).
     */
    public function moveTo(int $rank): void
    {
        $this->rank = $rank;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCategory(): IssueCategory
    {
        return $this->category;
    }

    public function getStatus(): IssueStatus
    {
        return $this->status;
    }

    public function getRequester(): IssueParty
    {
        return $this->requester->toParty();
    }

    public function getAssignee(): IssueParty
    {
        return $this->assignee->toParty();
    }

    public function getWaitingFor(): ?WaitingFor
    {
        return $this->waitingFor;
    }

    /**
     * The party that $waitingFor names.
     */
    public function waitsFor(): ?IssueParty
    {
        return match ($this->waitingFor) {
            WaitingFor::Requester => $this->getRequester(),
            WaitingFor::Assignee => $this->getAssignee(),
            null => null,
        };
    }

    /**
     * The mode that binds the issue: its approval in a by_issue project, where null reads as user, else the mode of
     * the project (D129).
     */
    public function getApprovalMode(): ApprovalMode
    {
        $mode = $this->project->getApprovalMode();

        return ApprovalMode::ByIssue === $mode ? $this->approval ?? ApprovalMode::User : $mode;
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

    /**
     * The stored claim, also after its end: {@see IssueClaim::held()}.
     */
    public function getClaim(): ?IssueClaim
    {
        $holder = $this->claimHolder->toActor();

        return null === $holder || null === $this->claimSession || null === $this->claimSince || null === $this->claimUntil
            ? null
            : new IssueClaim($holder, $this->claimSession, $this->claimSince, $this->claimUntil);
    }

    /**
     * Sets no updatedAt: a claim is no change of the issue.
     */
    public function claim(?IssueClaim $claim): void
    {
        $this->claimHolder = ActorEmbeddable::of($claim?->holder);
        $this->claimSession = $claim?->session;
        $this->claimSince = $claim?->since;
        $this->claimUntil = $claim?->until;
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

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function recordActivity(\DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
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

    /**
     * Sets no updatedAt, so a reclassification does not reorder the list (D133).
     */
    public function setCategory(IssueCategory $category): void
    {
        $this->category = $category;
    }

    public function setApproval(ApprovalMode $approval, \DateTimeImmutable $now): void
    {
        $this->approval = $approval;
        $this->updatedAt = $now;
    }

    public function setStatus(IssueStatus $status, ?WaitingFor $waitingFor, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->waitingFor = $waitingFor;
        $this->updatedAt = $now;
    }

    public function setRequester(IssueParty $requester, \DateTimeImmutable $now): void
    {
        $this->requester = IssuePartyEmbeddable::of($requester);
        $this->updatedAt = $now;
    }

    public function setAssignee(IssueParty $assignee, \DateTimeImmutable $now): void
    {
        $this->assignee = IssuePartyEmbeddable::of($assignee);
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
