<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Type\CommitShaType;
use App\Doctrine\WholeSeconds;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Repository\CommitLinkRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The projection of `commit.linked` and `commit.found` for one request. The payloads carry the git data,
 * so a rebuild needs no clone. The tenant comes from the request.
 */
#[ORM\Entity(repositoryClass: CommitLinkRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_commit_link_request_commit', columns: ['request_id', 'commit_sha'])]
#[ORM\Index(name: 'idx_commit_link_tenant_commit', columns: ['tenant_id', 'commit_sha'])]
class CommitLink implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ChangeRequest $request;

    #[ORM\Column(type: CommitShaType::NAME)]
    private CommitSha $commitSha;

    #[ORM\Column(length: 16, enumType: CommitLinkState::class)]
    private CommitLinkState $state;

    #[ORM\Embedded]
    private ActorEmbeddable $linkedBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $linkedAt;

    /**
     * The seq of the `commit.linked` event. It orders the links of a request.
     */
    #[ORM\Column(type: Types::BIGINT)]
    private int $linkedSeq;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $foundAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $committedAt = null;

    private function __construct(
        ChangeRequest $request,
        CommitSha $commitSha,
        Actor $linkedBy,
        \DateTimeImmutable $linkedAt,
        int $linkedSeq,
        ?string $note,
    ) {
        $this->id = new Ulid();
        $this->tenantId = $request->getTenantId()->toUlid();
        $this->request = $request;
        $this->commitSha = $commitSha;
        $this->state = CommitLinkState::Pending;
        $this->linkedBy = ActorEmbeddable::of($linkedBy);
        $this->linkedAt = WholeSeconds::require($linkedAt, 'linkedAt');
        $this->linkedSeq = $linkedSeq;
        $this->note = $note;
    }

    public static function pending(ChangeRequest $request, CommitSha $commitSha, Actor $linkedBy, \DateTimeImmutable $linkedAt, int $linkedSeq, ?string $note): self
    {
        return new self($request, $commitSha, $linkedBy, $linkedAt, $linkedSeq, $note);
    }

    /**
     * The commit is in the server clone at link time, so `foundAt` is the link time.
     */
    public static function available(
        ChangeRequest $request,
        CommitSha $commitSha,
        Actor $linkedBy,
        \DateTimeImmutable $linkedAt,
        int $linkedSeq,
        ?string $note,
        string $subject,
        \DateTimeImmutable $committedAt,
    ): self {
        $link = new self($request, $commitSha, $linkedBy, $linkedAt, $linkedSeq, $note);
        $link->markFound($linkedAt, $subject, $committedAt);

        return $link;
    }

    public function markFound(\DateTimeImmutable $foundAt, string $subject, \DateTimeImmutable $committedAt): void
    {
        $this->state = CommitLinkState::Available;
        $this->foundAt = WholeSeconds::require($foundAt, 'foundAt');
        $this->subject = $subject;
        $this->committedAt = WholeSeconds::require($committedAt, 'committedAt');
    }

    /**
     * Reuses the row of an unlinked pair. A delete and an insert of the same pair in one flush fail,
     * because Doctrine runs inserts before deletes.
     */
    public function relink(Actor $linkedBy, \DateTimeImmutable $linkedAt, int $linkedSeq, ?string $note): void
    {
        $this->state = CommitLinkState::Pending;
        $this->linkedBy = ActorEmbeddable::of($linkedBy);
        $this->linkedAt = WholeSeconds::require($linkedAt, 'linkedAt');
        $this->linkedSeq = $linkedSeq;
        $this->note = $note;
        $this->foundAt = null;
        $this->subject = null;
        $this->committedAt = null;
    }

    public function toLinkedCommit(): LinkedCommit
    {
        return new LinkedCommit($this->commitSha, $this->state);
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromUlid($this->tenantId);
    }

    public function getRequest(): ChangeRequest
    {
        return $this->request;
    }

    public function getRequestId(): RequestId
    {
        return $this->request->getId();
    }

    public function getCommitSha(): CommitSha
    {
        return $this->commitSha;
    }

    public function getState(): CommitLinkState
    {
        return $this->state;
    }

    public function getLinkedBy(): Actor
    {
        return $this->linkedBy->toActor();
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function getLinkedSeq(): int
    {
        return $this->linkedSeq;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getFoundAt(): ?\DateTimeImmutable
    {
        return $this->foundAt;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getCommittedAt(): ?\DateTimeImmutable
    {
        return $this->committedAt;
    }
}
