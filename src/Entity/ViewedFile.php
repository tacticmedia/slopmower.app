<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Git\RepoPath;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\ViewedFileId;
use App\Repository\ViewedFileRepository;
use App\Repository\ViewedFileSql;
use Doctrine\ORM\Mapping as ORM;

/**
 * One "Viewed" mark. {@see ViewedFileSql} writes the marks. The unique key holds the SHA-256 hex digest of the path,
 * because a B-tree entry holds at most about 2.7 kB and a path up to 4096 characters.
 */
#[ORM\Entity(repositoryClass: ViewedFileRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_viewed_file', columns: ['review_id', 'user_id', 'path_hash'])]
class ViewedFile implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: ViewedFileId::class)]
    private ViewedFileId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Review $review;

    #[ORM\Column(type: RepoPath::class)]
    private RepoPath $path;

    #[ORM\Column(length: 64)]
    private string $pathHash;

    public function __construct(User $user, Review $review, RepoPath $path)
    {
        if (!$review->getTenantId()->equals($user->getTenantId())) {
            throw new \LogicException('The review belongs to another tenant.');
        }

        $this->id = ViewedFileId::generate();
        $this->tenantId = $review->getTenantId();
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
        return $this->tenantId;
    }
}
