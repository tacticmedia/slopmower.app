<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\EventLogGuard;
use App\Doctrine\WholeSeconds;
use App\Domain\Actor\Actor;
use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\EventType;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Repository\ReviewEventRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One row of the append-only event log. `review_id` and `request_id` have no foreign keys,
 * because the log is the source of truth and a projection rebuild deletes the projections.
 * `payload` is a JSON object tree, so an empty payload stays `{}`. {@see EventLogGuard} refuses an ORM delete.
 */
#[ORM\Entity(repositoryClass: ReviewEventRepository::class, readOnly: true)]
#[ORM\UniqueConstraint(name: 'uniq_review_event_tenant_seq', columns: ['tenant_id', 'seq'])]
#[ORM\Index(name: 'idx_review_event_tenant_review_seq', columns: ['tenant_id', 'review_id', 'seq'])]
class ReviewEvent implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $seq;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $reviewId;

    #[ORM\Column(type: UlidType::NAME, nullable: true)]
    private ?Ulid $requestId;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $requestIds;

    #[ORM\Column(length: 64, enumType: EventType::class)]
    private EventType $type;

    #[ORM\Embedded]
    private ActorEmbeddable $actor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::JSONB_OBJECT)]
    private \stdClass $payload;

    private function __construct(RecordedEvent $recorded)
    {
        $this->id = $recorded->id->toUlid();
        $this->tenantId = $recorded->tenantId->toUlid();
        $this->seq = $recorded->seq;
        $this->reviewId = $recorded->reviewId->toUlid();
        $this->requestId = $recorded->requestId?->toUlid();
        $this->requestIds = array_map(strval(...), $recorded->requestIds());
        $this->type = $recorded->type;
        $this->actor = ActorEmbeddable::of($recorded->actor);
        $this->occurredAt = WholeSeconds::require($recorded->occurredAt, 'occurredAt');
        $payload = json_decode(EventCodec::encode($recorded->event), flags: \JSON_THROW_ON_ERROR);
        $this->payload = $payload instanceof \stdClass ? $payload : throw new \LogicException('An event payload must be a JSON object.');
    }

    public static function record(RecordedEvent $recorded): self
    {
        return new self($recorded);
    }

    public function toRecordedEvent(): RecordedEvent
    {
        return new RecordedEvent(
            $this->getId(),
            $this->getTenantId(),
            $this->seq,
            $this->getReviewId(),
            $this->getRequestId(),
            $this->type,
            $this->getActor(),
            $this->occurredAt,
            EventCodec::decode($this->type->value, json_encode($this->payload, \JSON_THROW_ON_ERROR)),
        );
    }

    public function getId(): EventId
    {
        return EventId::fromUlid($this->id);
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromUlid($this->tenantId);
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function getReviewId(): ReviewId
    {
        return ReviewId::fromUlid($this->reviewId);
    }

    public function getRequestId(): ?RequestId
    {
        return null === $this->requestId ? null : RequestId::fromUlid($this->requestId);
    }

    /**
     * @return list<RequestId>
     */
    public function getRequestIds(): array
    {
        return array_map(static fn (string $requestId): RequestId => RequestId::fromString($requestId, 'requestIds'), $this->requestIds);
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function getActor(): Actor
    {
        return $this->actor->toActor();
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
