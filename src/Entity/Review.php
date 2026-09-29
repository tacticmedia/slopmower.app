<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Embeddable\NullableActorEmbeddable;
use App\Doctrine\Type\CommitShaType;
use App\Doctrine\WholeSeconds;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Review\IndexState;
use App\Domain\Review\ReviewState;
use App\Repository\ReviewRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The tenant comes from the repository, so a review never points at a repository of another tenant.
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Index(name: 'idx_review_tenant_id', columns: ['tenant_id'])]
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

    #[ORM\Column(length: 255)]
    private string $ref;

    #[ORM\Column(type: CommitShaType::NAME)]
    private CommitSha $commitSha;

    #[ORM\Column(length: 16, enumType: ReviewState::class)]
    private ReviewState $state = ReviewState::Open;

    #[ORM\Embedded]
    private ActorEmbeddable $startedBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Embedded]
    private NullableActorEmbeddable $closedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $closeNote = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lastEventSeq;

    #[ORM\Column(length: 16, enumType: IndexState::class)]
    private IndexState $indexState = IndexState::Pending;

    #[ORM\Column(nullable: true)]
    private ?int $filesTotal = null;

    #[ORM\Column]
    private int $filesDone = 0;

    #[ORM\Column]
    private int $symbolsTotal = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $indexStartedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $indexFinishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $indexError = null;

    /**
     * @param int $lastEventSeq the seq of review.started
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
        $this->id = $id->toUlid();
        $this->tenantId = $repository->getTenantId()->toUlid();
        $this->repository = $repository;
        $this->title = $title;
        $this->ref = $ref->toString();
        $this->commitSha = $commitSha;
        $this->startedBy = ActorEmbeddable::of($startedBy);
        $this->createdAt = WholeSeconds::require($createdAt, 'createdAt');
        $this->closedBy = NullableActorEmbeddable::of(null);
        $this->lastEventSeq = $lastEventSeq;
    }

    public function close(\DateTimeImmutable $closedAt, Actor $closedBy, ?string $closeNote): void
    {
        $this->state = ReviewState::Closed;
        $this->closedAt = WholeSeconds::require($closedAt, 'closedAt');
        $this->closedBy = NullableActorEmbeddable::of($closedBy);
        $this->closeNote = $closeNote;
    }

    public function changeLastEventSeq(int $lastEventSeq): void
    {
        $this->lastEventSeq = $lastEventSeq;
    }

    public function changeIndexStatus(
        IndexState $indexState,
        ?int $filesTotal,
        int $filesDone,
        int $symbolsTotal,
        ?\DateTimeImmutable $indexStartedAt,
        ?\DateTimeImmutable $indexFinishedAt,
        ?string $indexError,
    ): void {
        $this->indexState = $indexState;
        $this->filesTotal = $filesTotal;
        $this->filesDone = $filesDone;
        $this->symbolsTotal = $symbolsTotal;
        $this->indexStartedAt = WholeSeconds::require($indexStartedAt, 'indexStartedAt');
        $this->indexFinishedAt = WholeSeconds::require($indexFinishedAt, 'indexFinishedAt');
        $this->indexError = $indexError;
    }

    public function getId(): ReviewId
    {
        return ReviewId::fromUlid($this->id);
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromUlid($this->tenantId);
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }

    public function getRepositoryId(): RepositoryId
    {
        return $this->repository->getId();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getRef(): RefName
    {
        return RefName::fromString($this->ref);
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
        return $this->startedBy->toActor();
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
