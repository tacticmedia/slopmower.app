<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Doctrine\WholeSeconds;
use App\Domain\Actor\Actor;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\TenantId;
use App\Repository\CommentRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The projection of one `request.commented` event. The tenant comes from the request.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Index(name: 'idx_comment_tenant_id', columns: ['tenant_id'])]
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

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
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
        $this->id = $id->toUlid();
        $this->tenantId = $request->getTenantId()->toUlid();
        $this->request = $request;
        $this->author = ActorEmbeddable::of($author);
        $this->body = $body;
        $this->question = $question;
        $this->batchId = $batchId?->toUlid();
        $this->createdAt = WholeSeconds::require($createdAt, 'createdAt');
    }

    public function getId(): CommentId
    {
        return CommentId::fromUlid($this->id);
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

    public function getAuthor(): Actor
    {
        return $this->author->toActor();
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
        return null === $this->batchId ? null : BatchId::fromUlid($this->batchId);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
