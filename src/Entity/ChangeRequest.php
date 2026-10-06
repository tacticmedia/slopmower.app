<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Type\AnchorType;
use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\MessageHandler\RecomputeLocationsHandler;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The projection of one request. {@see RequestStateMapper} maps it to and from the domain state.
 */
#[ORM\Entity(repositoryClass: ChangeRequestRepository::class)]
class ChangeRequest implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: RequestId::class)]
    private RequestId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Review $review;

    #[ORM\Column(type: AnchorType::NAME)]
    private Anchor $anchor;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Embedded]
    private ActorEmbeddable $author;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(enumType: RequestStatus::class)]
    private RequestStatus $status = RequestStatus::Open;

    #[ORM\Column(nullable: true, enumType: RequestStatus::class)]
    private ?RequestStatus $statusBeforeClaim = null;

    #[ORM\Embedded]
    private ActorEmbeddable $claimedBy;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true, enumType: ActorKind::class)]
    private ?ActorKind $wontDoBy = null;

    #[ORM\Column(nullable: true, enumType: Resolution::class)]
    private ?Resolution $resolution = null;

    #[ORM\Column(enumType: Awaiting::class)]
    private Awaiting $awaiting = Awaiting::Agent;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $lastUserCommentSeq = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $lastAgentEventSeq = null;

    #[ORM\Column]
    private bool $lastAgentEventIsQuestion = false;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lastEventSeq = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Not event data, so the projection rebuild keeps it. {@see RecomputeLocationsHandler} sets it. */
    #[ORM\Column(nullable: true)]
    private ?bool $outdated = null;

    /**
     * Sets the fields that request.opened fixes. {@see self::project()} sets the others.
     */
    public function __construct(RequestId $id, Review $review, Anchor $anchor, string $body, Actor $author, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->tenantId = $review->getTenantId();
        $this->review = $review;
        $this->opened($anchor, $body, $author, $createdAt);
        $this->claimedBy = ActorEmbeddable::of(null);
        $this->updatedAt = $createdAt;
    }

    /**
     * The projection rebuild recomputes an existing row in place.
     */
    public function opened(Anchor $anchor, string $body, Actor $author, \DateTimeImmutable $createdAt): void
    {
        $this->anchor = $anchor;
        $this->body = $body;
        $this->author = ActorEmbeddable::of($author);
        $this->createdAt = $createdAt;
    }

    public function project(
        RequestStatus $status,
        ?RequestStatus $statusBeforeClaim,
        ?Actor $claimedBy,
        ?\DateTimeImmutable $claimedAt,
        ?ActorKind $wontDoBy,
        ?Resolution $resolution,
        Awaiting $awaiting,
        ?int $lastUserCommentSeq,
        ?int $lastAgentEventSeq,
        bool $lastAgentEventIsQuestion,
        int $lastEventSeq,
        \DateTimeImmutable $updatedAt,
    ): void {
        $this->status = $status;
        $this->statusBeforeClaim = $statusBeforeClaim;
        $this->claimedBy = ActorEmbeddable::of($claimedBy);
        $this->claimedAt = $claimedAt;
        $this->wontDoBy = $wontDoBy;
        $this->resolution = $resolution;
        $this->awaiting = $awaiting;
        $this->lastUserCommentSeq = $lastUserCommentSeq;
        $this->lastAgentEventSeq = $lastAgentEventSeq;
        $this->lastAgentEventIsQuestion = $lastAgentEventIsQuestion;
        $this->lastEventSeq = $lastEventSeq;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): RequestId
    {
        return $this->id;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getReview(): Review
    {
        return $this->review;
    }

    public function getAnchor(): Anchor
    {
        return $this->anchor;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAuthor(): Actor
    {
        return $this->author->toActor() ?? throw new \UnexpectedValueException('A request has an author.');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function getStatusBeforeClaim(): ?RequestStatus
    {
        return $this->statusBeforeClaim;
    }

    public function getClaimedBy(): ?Actor
    {
        return $this->claimedBy->toActor();
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getWontDoBy(): ?ActorKind
    {
        return $this->wontDoBy;
    }

    public function getResolution(): ?Resolution
    {
        return $this->resolution;
    }

    public function getAwaiting(): Awaiting
    {
        return $this->awaiting;
    }

    public function getLastUserCommentSeq(): ?int
    {
        return $this->lastUserCommentSeq;
    }

    public function getLastAgentEventSeq(): ?int
    {
        return $this->lastAgentEventSeq;
    }

    public function isLastAgentEventQuestion(): bool
    {
        return $this->lastAgentEventIsQuestion;
    }

    public function getLastEventSeq(): int
    {
        return $this->lastEventSeq;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return bool|null True when the location at the default-branch head is changed or orphaned. Null until computed,
     *                   and while no clone or no default-branch head exists.
     */
    public function getOutdated(): ?bool
    {
        return $this->outdated;
    }

    public function recordOutdated(?bool $outdated): void
    {
        $this->outdated = $outdated;
    }
}
