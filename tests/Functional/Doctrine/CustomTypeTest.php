<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use App\Doctrine\Type\AnchorType;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The types come from the DBAL configuration, which the connection registers.
 */
final class CustomTypeTest extends KernelTestCase
{
    private const string COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string BLOB = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';
    private const string ULID = '01K6AZZ9Y8X7W6V5T4S3R2Q1P0';
    private const string UUID = '019995ff-a7c8-e9f8-6d97-44c8f02b86c0';

    protected function setUp(): void
    {
        self::getContainer()->get(Connection::class);
    }

    #[TestDox('Converts valid values in both directions')]
    #[DataProvider('validValues')]
    public function testConvertsValidValues(string $type, ?object $value, ?string $databaseValue): void
    {
        $platform = new PostgreSQLPlatform();

        self::assertSame($databaseValue, Type::getType($type)->convertToDatabaseValue($value, $platform));
        self::assertEquals($value, Type::getType($type)->convertToPHPValue($databaseValue, $platform));
    }

    /**
     * @return iterable<string, array{string, ?object, ?string}>
     */
    public static function validValues(): iterable
    {
        $anchor = Anchor::of(AnchorKind::File, RepoPath::fromString('src/Billing/InvoiceTotals.php'), CommitSha::fromString(self::COMMIT), BlobSha::fromString(self::BLOB), null, null, null);

        yield 'commit' => [CommitSha::class, CommitSha::fromString(self::COMMIT), self::COMMIT];
        yield 'blob' => [BlobSha::class, BlobSha::fromString(self::BLOB), self::BLOB];
        yield 'path' => [RepoPath::class, RepoPath::fromString('src/Billing/Größe.php'), 'src/Billing/Größe.php'];
        yield 'anchor' => [AnchorType::NAME, $anchor, '{"kind":"file","path":"src/Billing/InvoiceTotals.php","commitSha":"'.self::COMMIT.'","blobSha":"'.self::BLOB.'","range":null,"symbol":null,"snapshot":null}'];
        yield 'null' => [AnchorType::NAME, null, null];
        yield 'ID' => [TenantId::class, TenantId::fromString(self::ULID), self::UUID];
        yield 'null ID' => [TenantId::class, null, null];
    }

    #[TestDox('Takes the ULID string of a route parameter for an ID')]
    public function testTakesUlidStringForId(): void
    {
        self::assertSame(self::UUID, Type::getType(ReviewId::class)->convertToDatabaseValue(self::ULID, new PostgreSQLPlatform()));
    }

    #[TestDox('Rejects an invalid value for the database')]
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(string $type, mixed $value): void
    {
        $this->expectException(ConversionException::class);

        Type::getType($type)->convertToDatabaseValue($value, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'ID of another type' => [UserId::class, TenantId::fromString(self::ULID)];
        yield 'string that is no ULID' => [ReviewId::class, 'not-a-ulid'];
        yield 'commit as a string' => [CommitSha::class, self::COMMIT];
    }

    #[TestDox('Rejects an invalid database value')]
    #[DataProvider('invalidDatabaseValues')]
    public function testRejectsInvalidDatabaseValue(string $type, string $databaseValue): void
    {
        $this->expectException(ConversionException::class);

        Type::getType($type)->convertToPHPValue($databaseValue, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'upper-case commit' => [CommitSha::class, strtoupper(self::COMMIT)];
        yield 'short blob' => [BlobSha::class, substr(self::BLOB, 0, 12)];
        yield 'path with ..' => [RepoPath::class, 'src/../.env'];
        yield 'anchor without fields' => [AnchorType::NAME, '{"kind": "file"}'];
        yield 'anchor that is no JSON' => [AnchorType::NAME, 'file'];
        yield 'ID that is no ULID' => [TenantId::class, 'not-a-ulid'];
    }
}
