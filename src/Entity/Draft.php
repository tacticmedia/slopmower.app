<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\AnchorType;
use App\Domain\Anchor\Anchor;
use App\Domain\Identity\DraftId;
use App\Domain\Identity\TenantId;
use App\Repository\DraftRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A pending new request or reply of one user. Not event data, so a projection rebuild keeps it.
 */
#[ORM\Entity(repositoryClass: DraftRepository::class)]
#[ORM\Index(name: 'idx_draft_review_user', columns: ['review_id', 'user_id'])]
class Draft implements TenantOwned
{
    public const int MAX_PER_USER_AND_REVIEW = 500;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Review $review;

    #[ORM\Column(enumType: DraftKind::class)]
    private DraftKind $kind;

    #[ORM\Column(type: AnchorType::NAME, nullable: true)]
    private ?Anchor $anchor;

    #[ORM\ManyToOne]
    private ?ChangeRequest $request;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(User $user, Review $review, ?Anchor $anchor, ?ChangeRequest $request, string $body, \DateTimeImmutable $now)
    {
        if (!$review->getTenantId()->equals($user->getTenantId())) {
            throw new \LogicException('The review belongs to another tenant.');
        }

        $this->id = Ulid::fromString(DraftId::generate()->toString());
        $this->tenantId = Ulid::fromString($review->getTenantId()->toString());
        $this->user = $user;
        $this->review = $review;
        $this->kind = null === $request ? DraftKind::NewRequest : DraftKind::Reply;
        $this->anchor = $anchor;
        $this->request = $request;
        $this->body = $body;
        $this->createdAt = self::storedTime($now);
        $this->updatedAt = $this->createdAt;
    }

    /**
     * @param Anchor $anchor Captured at the review commit
     */
    public static function newRequest(User $user, Review $review, Anchor $anchor, string $body, \DateTimeImmutable $now): self
    {
        return new self($user, $review, $anchor, null, $body, $now);
    }

    public static function reply(User $user, ChangeRequest $request, string $body, \DateTimeImmutable $now): self
    {
        return new self($user, $request->getReview(), null, $request, $body, $now);
    }

    public function edit(string $body, \DateTimeImmutable $now): void
    {
        $this->body = $body;
        $this->updatedAt = self::storedTime($now);
    }

    public function getId(): DraftId
    {
        return DraftId::fromString($this->id->toBase32());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReview(): Review
    {
        return $this->review;
    }

    public function getKind(): DraftKind
    {
        return $this->kind;
    }

    /**
     * @return Anchor|null Null for a reply
     */
    public function getAnchor(): ?Anchor
    {
        return $this->anchor;
    }

    /**
     * @return ChangeRequest|null Null for a new request
     */
    public function getRequest(): ?ChangeRequest
    {
        return $this->request;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * UTC with second precision, as the column stores it, so the response equals what a reload returns.
     */
    private static function storedTime(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$time->getTimestamp());
    }
}
