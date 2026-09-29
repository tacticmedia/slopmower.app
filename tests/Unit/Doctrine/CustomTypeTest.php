<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Type\AnchorType;
use App\Doctrine\Type\BlobShaType;
use App\Doctrine\Type\CommitShaType;
use App\Doctrine\Type\RepoPathType;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
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
    private const string SHA1 = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string SHA256 = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string ANCHOR_JSON = '{"kind":"line","path":"src/Billing/Invoice Totals ü.php","commitSha":"79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091","blobSha":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef","range":{"startLine":2,"endLine":2},"symbol":null,"snapshot":{"lines":["$total = \"€\";"],"truncated":false,"lineCount":1,"before":["<?php"],"after":[],"normalizedHash":"sha256:045a8bc75a727f4136d646b606cd9b8c78a6aca643592c7f64af5e8f10253fd5"}}';

    /**
     * @return iterable<string, array{Type, object, string}>
     */
    public static function validValues(): iterable
    {
        yield 'SHA-1 commit' => [new CommitShaType(), CommitSha::fromString(self::SHA1), self::SHA1];
        yield 'SHA-1 blob' => [new BlobShaType(), BlobSha::fromString(self::SHA1), self::SHA1];
        yield 'path' => [new RepoPathType(), RepoPath::fromString('src/Billing/InvoiceTotals.php'), 'src/Billing/InvoiceTotals.php'];
        yield 'line anchor' => [new AnchorType(), self::lineAnchor(), self::ANCHOR_JSON];
    }

    #[DataProvider('validValues')]
    #[TestDox('Converts valid values in both directions')]
    public function testConvertsValidValuesInBothDirections(Type $type, object $phpValue, string $databaseValue): void
    {
        $platform = new PostgreSQLPlatform();

        self::assertSame($databaseValue, $type->convertToDatabaseValue($phpValue, $platform));
        self::assertEquals($phpValue, $type->convertToPHPValue($databaseValue, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    /**
     * @return iterable<string, array{Type, mixed}>
     */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'upper-case commit' => [new CommitShaType(), strtoupper(self::SHA1)];
        yield 'commit as an integer' => [new CommitShaType(), 42];
        yield 'blob with a non-hex character' => [new BlobShaType(), 'g'.substr(self::SHA1, 1)];
        yield 'path with a dot-dot segment' => [new RepoPathType(), 'src/../etc/passwd'];
        yield 'anchor that is no JSON' => [new AnchorType(), '{"kind":'];
        yield 'anchor without blobSha' => [new AnchorType(), str_replace('"blobSha"', '"blob"', self::ANCHOR_JSON)];
    }

    #[DataProvider('invalidDatabaseValues')]
    #[TestDox('Rejects an invalid database value')]
    public function testRejectsAnInvalidDatabaseValue(Type $type, mixed $databaseValue): void
    {
        $this->expectException(ConversionException::class);

        $type->convertToPHPValue($databaseValue, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{Type, mixed}>
     */
    public static function valuesOfAnotherType(): iterable
    {
        yield 'blob SHA as a commit' => [new CommitShaType(), BlobSha::fromString(self::SHA1)];
        yield 'commit SHA as a blob' => [new BlobShaType(), CommitSha::fromString(self::SHA1)];
        yield 'path as a string' => [new RepoPathType(), 'src/a.php'];
        yield 'anchor as an array' => [new AnchorType(), ['kind' => 'file']];
    }

    #[DataProvider('valuesOfAnotherType')]
    #[TestDox('Refuses to write a PHP value of another type')]
    public function testRefusesToWriteAPhpValueOfAnotherType(Type $type, mixed $phpValue): void
    {
        $this->expectException(ConversionException::class);

        $type->convertToDatabaseValue($phpValue, new PostgreSQLPlatform());
    }

    private static function lineAnchor(): Anchor
    {
        $range = LineRange::singleLine(2);

        return Anchor::line(
            RepoPath::fromString('src/Billing/Invoice Totals ü.php'),
            CommitSha::fromString(self::SHA1),
            BlobSha::fromString(self::SHA256),
            $range,
            SnapshotCapture::capture("<?php\n\$total = \"€\";\n", $range),
        );
    }
}
