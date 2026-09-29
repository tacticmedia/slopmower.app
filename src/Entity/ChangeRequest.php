<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Embeddable\NullableActorEmbeddable;
use App\Doctrine\Type\AnchorType;
use App\Doctrine\WholeSeconds;
use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The projection of one request. {@see RequestStateMapper} maps the lifecycle columns to and from the domain state.
 * The tenant comes from the review.
 */
#[ORM\Entity(repositoryClass: ChangeRequestRepository::class)]
#[ORM\Index(name: 'idx_change_request_tenant_id', columns: ['tenant_id'])]
class ChangeRequest implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Review $review;

    #[ORM\Column(type: AnchorType::NAME)]
    private Anchor $anchor;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Embedded]
    private ActorEmbeddable $author;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 32, enumType: RequestStatus::class)]
    private RequestStatus $status = RequestStatus::Open;

    #[ORM\Column(length: 32, nullable: true, enumType: RequestStatus::class)]
    private ?RequestStatus $statusBeforeClaim = null;

    #[ORM\Embedded]
    private NullableActorEmbeddable $claimedBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ActorKind::class)]
    private ?ActorKind $wontDoSetBy = null;

    #[ORM\Column(length: 32, nullable: true, enumType: RequestStatus::class)]
    private ?RequestStatus $verifiedFrom = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $lastUserEventSeq = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $lastAgentEventSeq = null;

    #[ORM\Column]
    private bool $lastAgentEventAsksQuestion = false;

    #[ORM\Column(length: 16, enumType: Awaiting::class)]
    private Awaiting $awaiting = Awaiting::Agent;

    #[ORM\Column(length: 16, nullable: true, enumType: Resolution::class)]
    private ?Resolution $resolution = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lastEventSeq;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Starts open and awaiting the agent. {@see RequestStateMapper::apply()} writes the reduced state.
     *
     * @param int $lastEventSeq the seq of request.opened
     */
    public function __construct(
        RequestId $id,
        Review $review,
        Anchor $anchor,
        string $body,
        Actor $author,
        \DateTimeImmutable $createdAt,
        int $lastEventSeq,
    ) {
        $this->id = $id->toUlid();
        $this->tenantId = $review->getTenantId()->toUlid();
        $this->review = $review;
        $this->anchor = $anchor;
        $this->body = $body;
        $this->author = ActorEmbeddable::of($author);
        $this->createdAt = WholeSeconds::require($createdAt, 'createdAt');
        $this->claimedBy = NullableActorEmbeddable::of(null);
        $this->lastEventSeq = $lastEventSeq;
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Call it through {@see RequestStateMapper::apply()}, which derives `awaiting` and `resolution` from the domain state.
     */
    public function changeLifecycle(
        RequestStatus $status,
        ?RequestStatus $statusBeforeClaim,
        ?Actor $claimedBy,
        ?\DateTimeImmutable $claimedAt,
        ?ActorKind $wontDoSetBy,
        ?RequestStatus $verifiedFrom,
        ?int $lastUserEventSeq,
        ?int $lastAgentEventSeq,
        bool $lastAgentEventAsksQuestion,
        Awaiting $awaiting,
        ?Resolution $resolution,
        int $lastEventSeq,
        \DateTimeImmutable $updatedAt,
    ): void {
        $this->status = $status;
        $this->statusBeforeClaim = $statusBeforeClaim;
        $this->claimedBy = NullableActorEmbeddable::of($claimedBy);
        $this->claimedAt = WholeSeconds::require($claimedAt, 'claimedAt');
        $this->wontDoSetBy = $wontDoSetBy;
        $this->verifiedFrom = $verifiedFrom;
        $this->lastUserEventSeq = $lastUserEventSeq;
        $this->lastAgentEventSeq = $lastAgentEventSeq;
        $this->lastAgentEventAsksQuestion = $lastAgentEventAsksQuestion;
        $this->awaiting = $awaiting;
        $this->resolution = $resolution;
        $this->lastEventSeq = $lastEventSeq;
        $this->updatedAt = WholeSeconds::require($updatedAt, 'updatedAt');
    }

    public function getId(): RequestId
    {
        return RequestId::fromUlid($this->id);
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromUlid($this->tenantId);
    }

    public function getReview(): Review
    {
        return $this->review;
    }

    public function getReviewId(): ReviewId
    {
        return $this->review->getId();
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
        return $this->author->toActor();
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

    public function getWontDoSetBy(): ?ActorKind
    {
        return $this->wontDoSetBy;
    }

    public function getVerifiedFrom(): ?RequestStatus
    {
        return $this->verifiedFrom;
    }

    public function getLastUserEventSeq(): ?int
    {
        return $this->lastUserEventSeq;
    }

    public function getLastAgentEventSeq(): ?int
    {
        return $this->lastAgentEventSeq;
    }

    public function lastAgentEventAsksQuestion(): bool
    {
        return $this->lastAgentEventAsksQuestion;
    }

    public function getAwaiting(): Awaiting
    {
        return $this->awaiting;
    }

    public function getResolution(): ?Resolution
    {
        return $this->resolution;
    }

    public function getLastEventSeq(): int
    {
        return $this->lastEventSeq;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
