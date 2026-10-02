<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\RepoPathType;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\TenantId;
use App\Repository\ViewedFileRepository;
use App\Repository\ViewedFileSql;
use App\Tenancy\TenantOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One "Viewed" mark. {@see ViewedFileSql} writes the marks. The unique key holds the SHA-256 hex digest of the path,
 * because a B-tree entry holds at most about 2.7 kB and a path up to 4096 characters.
 */
#[ORM\Entity(repositoryClass: ViewedFileRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_viewed_file', columns: ['review_id', 'user_id', 'path_hash'])]
class ViewedFile implements TenantOwned
{
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

    #[ORM\Column(type: RepoPathType::NAME)]
    private RepoPath $path;

    #[ORM\Column(length: 64)]
    private string $pathHash;

    public function __construct(User $user, Review $review, RepoPath $path)
    {
        if (!$review->getTenantId()->equals($user->getTenantId())) {
            throw new \LogicException('The review belongs to another tenant.');
        }

        $this->id = new Ulid();
        $this->tenantId = Ulid::fromString($review->getTenantId()->toString());
        $this->user = $user;
        $this->review = $review;
        $this->path = $path;
        $this->pathHash = self::hash($path);
    }

    public static function hash(RepoPath $path): string
    {
        return hash('sha256', $path->toString());
    }

    public function getTenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId->toBase32());
    }
}
