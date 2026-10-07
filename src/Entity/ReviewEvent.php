<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Event\Codec\CorruptPayload;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Event\EventType;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Repository\ReviewEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only. The event store inserts rows with SQL, so the entity has no constructor.
 * `request_ids` holds {@see RecordedEvent::requestIds()}, so thread and updates queries select events by request.
 */
#[ORM\Entity(repositoryClass: ReviewEventRepository::class, readOnly: true)]
#[ORM\UniqueConstraint(name: 'uniq_review_event_seq', columns: ['tenant_id', 'seq'])]
#[ORM\Index(name: 'idx_review_event_review', columns: ['tenant_id', 'review_id', 'seq'])]
// GIN with jsonb_path_ops for `@>`, from the migration. DBAL cannot declare an index method.
#[ORM\Index(name: 'idx_review_event_request_ids', columns: ['request_ids'])]
class ReviewEvent implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: EventId::class)]
    private EventId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $seq;

    #[ORM\Column(type: ReviewId::class)]
    private ReviewId $reviewId;

    #[ORM\Column(type: RequestId::class, nullable: true)]
    private ?RequestId $requestId;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $requestIds;

    #[ORM\Column(enumType: EventType::class)]
    private EventType $type;

    #[ORM\Embedded]
    private ActorEmbeddable $actor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @var array<mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $payload;

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    /**
     * @return list<RequestId>
     *
     * @throws CorruptPayload
     */
    public function getRequestIds(): array
    {
        return PayloadReader::of(['requestIds' => $this->requestIds])->idList('requestIds', RequestId::class);
    }

    /**
     * @throws CorruptPayload
     */
    public function toRecordedEvent(): RecordedEvent
    {
        return new RecordedEvent(
            $this->id,
            $this->getTenantId(),
            $this->seq,
            $this->reviewId,
            $this->requestId,
            $this->actor->toActor() ?? throw new \UnexpectedValueException('An event has an actor.'),
            $this->occurredAt,
            $this->type->decode($this->payload),
        );
    }
}
