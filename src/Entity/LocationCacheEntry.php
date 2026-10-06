<?php

declare(strict_types=1);

namespace App\Entity;

use App\Anchoring\LocationCacheSql;
use App\Domain\Anchor\LineRange;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Domain\Location\Location;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\OrphanReason;
use App\Repository\LocationCacheEntryRepository;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Architecture section 5.2: a location keyed by the `request.opened` event ID and the target SHA. Valid forever, because
 * both commits are immutable. {@see LocationCacheSql} writes the rows.
 */
#[ORM\Entity(repositoryClass: LocationCacheEntryRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_location_cache_entry_repository', columns: ['tenant_id', 'repository_id'])]
class LocationCacheEntry implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: EventId::class)]
    private EventId $openedEventId;

    #[ORM\Id]
    #[ORM\Column(type: CommitSha::class)]
    private CommitSha $targetSha;

    #[ORM\Column(type: RepositoryId::class)]
    private RepositoryId $repositoryId;

    #[ORM\Column(enumType: LocationStatus::class)]
    private LocationStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $path;

    #[ORM\Column(nullable: true)]
    private ?int $startLine;

    #[ORM\Column(nullable: true)]
    private ?int $endLine;

    #[ORM\Column]
    private float $confidence;

    #[ORM\Column(nullable: true, enumType: OrphanReason::class)]
    private ?OrphanReason $reason;

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function toLocation(): Location
    {
        $path = null === $this->path ? null : RepoPath::fromString($this->path);
        $range = null === $this->startLine || null === $this->endLine ? null : LineRange::of($this->startLine, $this->endLine);
        $missing = static fn (string $column): \UnexpectedValueException => new \UnexpectedValueException("A {$column} is missing in a location cache entry.");

        return match ($this->status) {
            LocationStatus::Exact => Location::exact($this->targetSha, $path ?? throw $missing('path'), $range),
            LocationStatus::Shifted => Location::shifted($this->targetSha, $path ?? throw $missing('path'), $range),
            LocationStatus::Changed => Location::changed($this->targetSha, $path ?? throw $missing('path'), $range, $this->confidence),
            LocationStatus::Orphaned => Location::orphaned($this->targetSha, $this->reason ?? throw $missing('reason')),
        };
    }
}
