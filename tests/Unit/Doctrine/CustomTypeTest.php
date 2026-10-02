<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Type\AnchorType;
use App\Doctrine\Type\BlobShaType;
use App\Doctrine\Type\CommitShaType;
use App\Doctrine\Type\RepoPathType;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CustomTypeTest extends KernelTestCase
{
    private const string COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string BLOB = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';

    #[TestDox('Converts valid values in both directions')]
    #[DataProvider('validValues')]
    public function testConvertsValidValues(Type $type, ?object $value, ?string $databaseValue): void
    {
        $platform = new PostgreSQLPlatform();

        self::assertSame($databaseValue, $type->convertToDatabaseValue($value, $platform));
        self::assertEquals($value, $type->convertToPHPValue($databaseValue, $platform));
    }

    /**
     * @return iterable<string, array{Type, ?object, ?string}>
     */
    public static function validValues(): iterable
    {
        $anchor = Anchor::of(AnchorKind::File, RepoPath::fromString('src/Billing/InvoiceTotals.php'), CommitSha::fromString(self::COMMIT), BlobSha::fromString(self::BLOB), null, null, null);

        yield 'commit' => [new CommitShaType(), CommitSha::fromString(self::COMMIT), self::COMMIT];
        yield 'blob' => [new BlobShaType(), BlobSha::fromString(self::BLOB), self::BLOB];
        yield 'path' => [new RepoPathType(), RepoPath::fromString('src/Billing/Größe.php'), 'src/Billing/Größe.php'];
        yield 'anchor' => [new AnchorType(), $anchor, '{"kind":"file","path":"src/Billing/InvoiceTotals.php","commitSha":"'.self::COMMIT.'","blobSha":"'.self::BLOB.'","range":null,"symbol":null,"snapshot":null}'];
        yield 'null' => [new AnchorType(), null, null];
    }

    #[TestDox('Rejects an invalid database value')]
    #[DataProvider('invalidDatabaseValues')]
    public function testRejectsInvalidDatabaseValue(Type $type, string $databaseValue): void
    {
        $this->expectException(ConversionException::class);

        $type->convertToPHPValue($databaseValue, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{Type, string}>
     */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'upper-case commit' => [new CommitShaType(), strtoupper(self::COMMIT)];
        yield 'short blob' => [new BlobShaType(), substr(self::BLOB, 0, 12)];
        yield 'path with ..' => [new RepoPathType(), 'src/../.env'];
        yield 'anchor without fields' => [new AnchorType(), '{"kind": "file"}'];
        yield 'anchor that is no JSON' => [new AnchorType(), 'file'];
    }
}
