<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Entity\LocationCacheEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<LocationCacheEntry>
 */
final class LocationCacheEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationCacheEntry::class);
    }

    public function findOneByKey(EventId $openedEventId, CommitSha $targetSha): ?LocationCacheEntry
    {
        return $this->findOneBy(['openedEventId' => Ulid::fromString($openedEventId->toString()), 'targetSha' => $targetSha]);
    }
}
