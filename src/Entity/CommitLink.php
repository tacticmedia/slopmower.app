<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\Type\CommitShaType;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\TenantId;
use App\Domain\Request\CommitLinkState;
use App\Repository\CommitLinkRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The primary key makes a link unique per request and commit. The git data comes from the commit.linked or
 * commit.found payload, so a rebuild needs no clone.
 */
#[ORM\Entity(repositoryClass: CommitLinkRepository::class)]
class CommitLink implements TenantOwned
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ChangeRequest $request;

    #[ORM\Id]
    #[ORM\Column(type: CommitShaType::NAME)]
    private CommitSha $commitSha;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\Column(enumType: CommitLinkState::class)]
    private CommitLinkState $state = CommitLinkState::Pending;

    #[ORM\Embedded]
    private ActorEmbeddable $linkedBy;

    #[ORM\Column]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $foundAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $committedAt = null;

    /**
     * A pending link. {@see self::found()} makes it available.
     */
    public function __construct(ChangeRequest $request, CommitSha $commitSha, Actor $linkedBy, \DateTimeImmutable $linkedAt, ?string $note)
    {
        $this->tenantId = Ulid::fromString($request->getTenantId()->toString());
        $this->request = $request;
        $this->commitSha = $commitSha;
        $this->linkedBy = ActorEmbeddable::of($linkedBy);
        $this->linkedAt = $linkedAt;
        $this->note = $note;
    }

    /**
     * @param \DateTimeImmutable $foundAt The link time for a commit that is available at link time
     */
    public function found(\DateTimeImmutable $foundAt, string $subject, \DateTimeImmutable $committedAt): void
    {
        $this->state = CommitLinkState::Available;
        $this->foundAt = $foundAt;
        $this->subject = $subject;
        $this->committedAt = $committedAt;
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function getRequest(): ChangeRequest
    {
        return $this->request;
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
        return $this->linkedBy->toActor() ?? throw new \UnexpectedValueException('A commit link has a linkedBy actor.');
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
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
