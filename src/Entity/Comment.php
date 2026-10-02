<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\TenantId;
use App\Repository\CommentRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
class Comment implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ChangeRequest $request;

    #[ORM\Embedded]
    private ActorEmbeddable $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private bool $question;

    #[ORM\Column(type: UlidType::NAME, nullable: true)]
    private ?Ulid $batchId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        CommentId $id,
        ChangeRequest $request,
        Actor $author,
        string $body,
        bool $question,
        ?BatchId $batchId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Ulid::fromString($id->toString());
        $this->tenantId = Ulid::fromString($request->getTenantId()->toString());
        $this->request = $request;
        $this->author = ActorEmbeddable::of($author);
        $this->body = $body;
        $this->question = $question;
        $this->batchId = null === $batchId ? null : Ulid::fromString($batchId->toString());
        $this->createdAt = $createdAt;
    }

    public function getId(): CommentId
    {
        return CommentId::fromString($this->id->toBase32());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }

    public function getRequest(): ChangeRequest
    {
        return $this->request;
    }

    public function getAuthor(): Actor
    {
        return $this->author->toActor() ?? throw new \UnexpectedValueException('A comment has an author.');
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isQuestion(): bool
    {
        return $this->question;
    }

    public function getBatchId(): ?BatchId
    {
        return null === $this->batchId ? null : BatchId::fromString($this->batchId->toBase32());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
