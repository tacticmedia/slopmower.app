<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Event\Codec\CorruptPayload;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\SuggestionState;
use App\MessageHandler\RecomputeLocationsHandler;
use App\Repository\ChangeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The projection of one request: `state` holds {@see RequestState::toPayload()} (D98), and the other state columns
 * are copies for SQL filters and sorts.
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

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $state;

    #[ORM\Column(enumType: RequestStatus::class)]
    private RequestStatus $status;

    #[ORM\Column(enumType: Awaiting::class)]
    private Awaiting $awaiting;

    #[ORM\Column(nullable: true, enumType: SuggestionState::class)]
    private ?SuggestionState $suggestionState;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lastEventSeq;

    /** Not event data, so the projection rebuild keeps it. {@see RecomputeLocationsHandler} sets it. */
    #[ORM\Column(nullable: true)]
    private ?bool $outdated = null;

    public function __construct(Review $review, RequestState $state)
    {
        if (!$review->getId()->equals($state->reviewId)) {
            throw new \LogicException("The request {$state->id} belongs to another review.");
        }

        $this->id = $state->id;
        $this->tenantId = $review->getTenantId();
        $this->review = $review;
        $this->project($state);
    }

    public function project(RequestState $state): void
    {
        if (!$this->id->equals($state->id)) {
            throw new \LogicException("The state of {$state->id} does not fit the request {$this->id}.");
        }

        $this->state = $state->toPayload();
        $this->status = $state->status;
        $this->awaiting = AwaitingPolicy::evaluate($state);
        $this->suggestionState = $state->suggestion;
        $this->updatedAt = $state->updatedAt;
        $this->lastEventSeq = $state->lastEventSeq;
    }

    /**
     * @throws CorruptPayload
     */
    public function getState(): RequestState
    {
        return RequestState::fromPayload($this->id, $this->review->getId(), $this->state);
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

    public function getAwaiting(): Awaiting
    {
        return $this->awaiting;
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
