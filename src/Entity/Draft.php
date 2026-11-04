<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\AnchorType;
use App\Domain\Anchor\Anchor;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\DraftId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\RequestType;
use App\Repository\DraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pending new request or reply of one user. Not event data, so a projection rebuild keeps it.
 */
#[ORM\Entity(repositoryClass: DraftRepository::class)]
#[ORM\Index(name: 'idx_draft_review_user', columns: ['review_id', 'user_id'])]
class Draft implements TenantOwned
{
    public const int MAX_PER_USER_AND_REVIEW = 500;

    #[ORM\Id]
    #[ORM\Column(type: DraftId::class)]
    private DraftId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

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

    /** Always null for a reply. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** Always false for a reply. */
    #[ORM\Column(options: ['default' => false])]
    private bool $requireApproval;

    /** Always change for a reply. */
    #[ORM\Column(enumType: RequestType::class, options: ['default' => 'change'])]
    private RequestType $type;

    /** Always null for a reply. `group` is an SQL keyword. */
    #[ORM\Column(name: 'group_label', length: 80, nullable: true)]
    private ?string $group;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(User $user, Review $review, ?Anchor $anchor, ?ChangeRequest $request, ?string $title, string $body, bool $requireApproval, RequestType $type, ?string $group, \DateTimeImmutable $now)
    {
        if (!$review->getTenantId()->equals($user->getTenantId())) {
            throw new \LogicException('The review belongs to another tenant.');
        }

        $this->id = DraftId::generate();
        $this->tenantId = $review->getTenantId();
        $this->user = $user;
        $this->review = $review;
        $this->kind = null === $request ? DraftKind::NewRequest : DraftKind::Reply;
        $this->anchor = $anchor;
        $this->request = $request;
        $this->title = self::trimmed($title);
        $this->body = $body;
        $this->requireApproval = $requireApproval;
        $this->type = $type;
        $this->group = self::trimmed($group);
        $this->createdAt = self::storedTime($now);
        $this->updatedAt = $this->createdAt;
    }

    /**
     * @param Anchor|null $anchor Captured at the review commit. Null for a general request.
     * @param string|null $title  Stored trimmed
     * @param string|null $group  Stored trimmed
     */
    public static function newRequest(User $user, Review $review, ?Anchor $anchor, ?string $title, string $body, bool $requireApproval, RequestType $type, ?string $group, \DateTimeImmutable $now): self
    {
        return new self($user, $review, $anchor, null, $title, $body, $requireApproval, $type, $group, $now);
    }

    public static function reply(User $user, ChangeRequest $request, string $body, \DateTimeImmutable $now): self
    {
        return new self($user, $request->getReview(), null, $request, null, $body, false, RequestType::Change, null, $now);
    }

    /**
     * Replaces the title, the body, and the group together, so a null title or group removes it.
     *
     * @param bool|null        $requireApproval Null keeps the value
     * @param RequestType|null $type            Null keeps the value
     * @param Anchor|null      $anchor          Captured at the review commit. Null keeps the anchor
     *
     * @throws InvalidInput For a reply that gets a title, a requireApproval, a type, a group, or an anchor
     */
    public function edit(?string $title, string $body, ?bool $requireApproval, ?RequestType $type, ?string $group, ?Anchor $anchor, \DateTimeImmutable $now): void
    {
        if (null !== $title && DraftKind::Reply === $this->kind) {
            throw InvalidInput::field('title', 'Only a new request has a title.');
        }

        if (null !== $requireApproval && DraftKind::Reply === $this->kind) {
            throw InvalidInput::field('requireApproval', 'Only a new request asks for a plan first.');
        }

        if (null !== $type && DraftKind::Reply === $this->kind) {
            throw InvalidInput::field('type', 'Only a new request has a type.');
        }

        if (null !== $group && DraftKind::Reply === $this->kind) {
            throw InvalidInput::field('group', 'Only a new request has a group.');
        }

        if (null !== $anchor && DraftKind::Reply === $this->kind) {
            throw InvalidInput::field('anchor', 'Only a new request has an anchor.');
        }

        $this->title = self::trimmed($title);
        $this->body = $body;
        $this->requireApproval = $requireApproval ?? $this->requireApproval;
        $this->type = $type ?? $this->type;
        $this->group = self::trimmed($group);
        $this->anchor = $anchor ?? $this->anchor;
        $this->updatedAt = self::storedTime($now);
    }

    public function getId(): DraftId
    {
        return $this->id;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
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
     * @return Anchor|null Null for a reply and for a general request
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function requiresApproval(): bool
    {
        return $this->requireApproval;
    }

    public function getType(): RequestType
    {
        return $this->type;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function trimmed(?string $text): ?string
    {
        return null === $text ? null : trim($text);
    }

    /**
     * UTC with second precision, as the column stores it, so the response equals what a reload returns.
     */
    private static function storedTime(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$time->getTimestamp());
    }
}
