<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Embeddable\ActorEmbeddable;
use App\Domain\Actor\Actor;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\TenantId;
use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
class Comment implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: CommentId::class)]
    private CommentId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ChangeRequest $request;

    #[ORM\Embedded]
    private ActorEmbeddable $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private bool $question;

    #[ORM\Column(type: BatchId::class, nullable: true)]
    private ?BatchId $batchId;

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
        $this->id = $id;
        $this->tenantId = $request->getTenantId();
        $this->request = $request;
        $this->author = ActorEmbeddable::of($author);
        $this->body = $body;
        $this->question = $question;
        $this->batchId = $batchId;
        $this->createdAt = $createdAt;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getAuthor(): Actor
    {
        return $this->author->toActor() ?? throw new \UnexpectedValueException('A comment has an author.');
    }
}
