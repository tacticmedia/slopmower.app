<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Anchoring\LocationCacheSql;
use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\Location;
use App\Domain\Review\Command\StartReview;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Index\IndexSql;
use App\Repository\DeletionSql;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Deletion\TenantRows;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A second connection runs a deletion of DeletionSql and holds its transaction open, as a request does during V13 or R6.
 */
final class DeletionRaceTest extends KernelTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use TenantRows;

    private const string COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';

    /** SQLSTATE lock_not_available. */
    private const string LOCK_TIMEOUT = '55P03';

    #[TestDox('An index write waits for a repository delete, then writes nothing')]
    public function testIndexWriteWaitsForRepositoryDelete(): void
    {
        $user = TenantFixtures::of(self::getContainer())->create();
        $repository = self::storeRepository($user->getTenantId(), 'billing');
        $write = static fn () => self::getContainer()->get(IndexSql::class)->insertFiles(
            $user->getTenantId(),
            $repository->getId(),
            CommitSha::fromString(self::COMMIT),
            [new TreeEntry('README.md', '100644', ObjectType::Blob, 'e965047ad7c57865823c7d992b1d046ea66edf78', 6)],
        );

        $deletion = self::openDeletion(static fn (DeletionSql $sql) => $sql->deleteRepository($user->getTenantId(), $repository->getId()));
        $whileDeleting = self::sqlStateOf($write);
        $deletion->commit();
        $deletion->close();
        $write();

        self::assertSame(self::LOCK_TIMEOUT, $whileDeleting);
        self::assertSame(['repository' => 0, 'indexed_file' => 0], self::tenantRowCountsOf($user->getTenantId(), ['repository', 'indexed_file']));
    }

    #[TestDox('A location cache write waits for a review delete, then writes nothing')]
    public function testLocationWriteWaitsForReviewDelete(): void
    {
        $user = TenantFixtures::of(self::getContainer())->create();
        $repository = self::storeRepository($user->getTenantId(), 'billing');
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        $reviewId = ReviewId::generate();
        $opened = self::getContainer()->get(ReviewCommands::class)
            ->recordStarted($reviewId, $repository->getId(), new StartReview('Race', RefName::fromString('main'), CommitSha::fromString(self::COMMIT)), Actor::user($user->getId()))[0];
        $write = static fn () => self::getContainer()->get(LocationCacheSql::class)->insert(
            $user->getTenantId(),
            $repository->getId(),
            $opened->id,
            Location::exact(CommitSha::fromString(self::COMMIT), RepoPath::fromString('README.md'), null),
        );

        $deletion = self::openDeletion(static fn (DeletionSql $sql) => $sql->deleteReview($user->getTenantId(), $reviewId));
        $whileDeleting = self::sqlStateOf($write);
        $deletion->commit();
        $deletion->close();
        $write();

        self::assertSame(self::LOCK_TIMEOUT, $whileDeleting);
        self::assertSame(['review' => 0, 'location_cache_entry' => 0], self::tenantRowCountsOf($user->getTenantId(), ['review', 'location_cache_entry']));
    }

    /**
     * @param \Closure(DeletionSql): void $delete
     *
     * @return Connection With the transaction of the deletion open
     */
    private static function openDeletion(\Closure $delete): Connection
    {
        $second = DriverManager::getConnection(self::getContainer()->get(Connection::class)->getParams());
        $second->beginTransaction();
        $delete(new DeletionSql($second));

        return $second;
    }

    /**
     * @param \Closure(): void $write
     *
     * @return string|null The SQLSTATE of the failure, null after success
     */
    private static function sqlStateOf(\Closure $write): ?string
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("SET lock_timeout = '200ms'");

        try {
            $write();

            return null;
        } catch (DriverException $failure) {
            return $failure->getSQLState();
        } finally {
            $connection->executeStatement('RESET lock_timeout');
        }
    }
}
