<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Type\CommitShaType;
use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Review\IndexState;
use App\Domain\Review\ReviewSnapshot;
use App\Domain\Review\ReviewState;
use App\Repository\ReviewRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The index fields are not event data, so a projection rebuild keeps them.
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Index(name: 'idx_review_tenant', columns: ['tenant_id'])]
class Review implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Repository $repository;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column]
    private string $ref;

    #[ORM\Column(type: CommitShaType::NAME)]
    private CommitSha $commitSha;

    #[ORM\Column(enumType: ReviewState::class)]
    private ReviewState $state = ReviewState::Open;

    #[ORM\Embedded]
    private ActorEmbeddable $startedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Embedded]
    private ActorEmbeddable $closedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $closeNote = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lastEventSeq;

    #[ORM\Column(enumType: IndexState::class)]
    private IndexState $indexState = IndexState::Pending;

    #[ORM\Column(nullable: true)]
    private ?int $filesTotal = null;

    #[ORM\Column]
    private int $filesDone = 0;

    #[ORM\Column]
    private int $symbolsTotal = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $indexStartedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $indexFinishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $indexError = null;

    /**
     * @param int $lastEventSeq The seq of review.started
     */
    public function __construct(
        ReviewId $id,
        Repository $repository,
        string $title,
        RefName $ref,
        CommitSha $commitSha,
        Actor $startedBy,
        \DateTimeImmutable $createdAt,
        int $lastEventSeq,
    ) {
        $this->id = Ulid::fromString($id->toString());
        $this->tenantId = Ulid::fromString($repository->getTenantId()->toString());
        $this->repository = $repository;
        $this->title = $title;
        $this->ref = $ref->toString();
        $this->commitSha = $commitSha;
        $this->startedBy = ActorEmbeddable::of($startedBy);
        $this->createdAt = $createdAt;
        $this->closedBy = ActorEmbeddable::of(null);
        $this->lastEventSeq = $lastEventSeq;
    }

    public function project(ReviewSnapshot $snapshot, int $lastEventSeq): void
    {
        if (!$snapshot->id->equals($this->getId())) {
            throw new \LogicException("The snapshot of {$snapshot->id} does not fit the review {$this->getId()}.");
        }

        $this->title = $snapshot->title;
        $this->ref = $snapshot->ref->toString();
        $this->commitSha = $snapshot->commitSha;
        $this->startedBy = ActorEmbeddable::of($snapshot->startedBy);
        $this->createdAt = $snapshot->createdAt;
        $this->state = $snapshot->isClosed() ? ReviewState::Closed : ReviewState::Open;
        $this->closedAt = $snapshot->closedAt;
        $this->closedBy = ActorEmbeddable::of($snapshot->closedBy);
        $this->closeNote = $snapshot->closeNote;
        $this->lastEventSeq = $lastEventSeq;
    }

    public function toSnapshot(): ReviewSnapshot
    {
        return new ReviewSnapshot(
            id: $this->getId(),
            title: $this->title,
            ref: $this->getRef(),
            commitSha: $this->commitSha,
            startedBy: $this->getStartedBy(),
            createdAt: $this->createdAt,
            closedAt: $this->closedAt,
            closedBy: $this->getClosedBy(),
            closeNote: $this->closeNote,
        );
    }

    public function getId(): ReviewId
    {
        return ReviewId::fromString($this->id->toBase32());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @throws \UnexpectedValueException For a stored ref that breaks the ref rules, so the caller gets no invalid_input
     */
    public function getRef(): RefName
    {
        try {
            return RefName::fromString($this->ref);
        } catch (InvalidInput $error) {
            throw new \UnexpectedValueException("Corrupt ref column. {$error->getMessage()}", 0, $error);
        }
    }

    public function getCommitSha(): CommitSha
    {
        return $this->commitSha;
    }

    public function getState(): ReviewState
    {
        return $this->state;
    }

    public function getStartedBy(): Actor
    {
        return $this->startedBy->toActor() ?? throw new \UnexpectedValueException('A review has a startedBy actor.');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?Actor
    {
        return $this->closedBy->toActor();
    }

    public function getCloseNote(): ?string
    {
        return $this->closeNote;
    }

    public function getLastEventSeq(): int
    {
        return $this->lastEventSeq;
    }

    public function getIndexState(): IndexState
    {
        return $this->indexState;
    }

    public function getFilesTotal(): ?int
    {
        return $this->filesTotal;
    }

    public function getFilesDone(): int
    {
        return $this->filesDone;
    }

    public function getSymbolsTotal(): int
    {
        return $this->symbolsTotal;
    }

    public function getIndexStartedAt(): ?\DateTimeImmutable
    {
        return $this->indexStartedAt;
    }

    public function getIndexFinishedAt(): ?\DateTimeImmutable
    {
        return $this->indexFinishedAt;
    }

    public function getIndexError(): ?string
    {
        return $this->indexError;
    }
}
