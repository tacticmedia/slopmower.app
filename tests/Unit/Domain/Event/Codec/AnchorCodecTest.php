<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnchorCodecTest extends KernelTestCase
{
    use ViolatedFields;

    private const string COMMIT_SHA = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string BLOB_SHA = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';
    private const string HASH = 'sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9';

    /**
     * The `anchor` of the request.opened example in docs/architecture.md section 3.
     */
    private const string ARCHITECTURE_EXAMPLE_ANCHOR = <<<'JSON'
        {
          "kind": "symbol",
          "path": "src/Billing/InvoiceTotals.php",
          "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
          "blobSha": "3b18e512dba79e4c8300dd08aeb37f8e728b8dad",
          "range": {"startLine": 38, "endLine": 49},
          "symbol": {"language": "php", "kind": "method", "fqn": "App\\Billing\\InvoiceTotals::total()"},
          "snapshot": {
            "lines": ["    /**", "     * Sum of all invoice lines.", "     */", "    public function total(): float", "    {", "        $sum = 0.0;", "        foreach ($this->lines as $line) {", "            $sum += $line->amount;", "        }", "", "        return $sum;", "    }"],
            "truncated": false, "lineCount": 12, "before": ["    }", "", ""], "after": ["", "    public function tax(): float", "    {"],
            "normalizedHash": "sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9"
          }
        }
        JSON;

    #[TestDox('Reads the architecture example anchor and writes it back key for key')]
    public function testRoundTripsTheArchitectureExampleAnchor(): void
    {
        $example = PayloadJson::decode(self::ARCHITECTURE_EXAMPLE_ANCHOR);

        $anchor = AnchorCodec::decode(PayloadReader::of($example, 'payload.anchor'));

        self::assertSame(['App\Billing\InvoiceTotals::total()', 38, 12], [$anchor->symbol?->fqn, $anchor->range?->startLine, $anchor->snapshot?->lineCount]);
        self::assertSame(PayloadJson::encode($example), PayloadJson::encode(AnchorCodec::encode($anchor)));
    }

    #[TestDox('Writes explicit nulls for the range, symbol, and snapshot of a file anchor')]
    public function testWritesExplicitNullsForAFileAnchor(): void
    {
        $anchor = Anchor::file(self::path(), self::commitSha(), self::blobSha());

        self::assertSame(
            '{"kind":"file","path":"src/Billing/InvoiceTotals.php","commitSha":"'.self::COMMIT_SHA.'","blobSha":"'.self::BLOB_SHA.'","range":null,"symbol":null,"snapshot":null}',
            PayloadJson::encode(AnchorCodec::encode($anchor)),
        );
    }

    /**
     * @return iterable<string, array{Anchor}>
     */
    public static function anchors(): iterable
    {
        $range = LineRange::of(38, 39);

        yield 'file' => [Anchor::file(self::path(), self::commitSha(), self::blobSha())];
        yield 'line' => [Anchor::line(self::path(), self::commitSha(), self::blobSha(), LineRange::singleLine(7), self::snapshot(1))];
        yield 'range' => [Anchor::range(self::path(), self::commitSha(), self::blobSha(), $range, self::snapshot(2))];
        yield 'symbol' => [Anchor::symbol(self::path(), self::commitSha(), self::blobSha(), $range, SymbolRef::php(SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'), self::snapshot(2))];
    }

    #[DataProvider('anchors')]
    #[TestDox('Round-trips a file, line, range, and symbol anchor')]
    public function testRoundTripsEachKind(Anchor $anchor): void
    {
        $decoded = AnchorCodec::decode(PayloadReader::of(PayloadJson::decode(PayloadJson::encode(AnchorCodec::encode($anchor))), 'payload.anchor'));

        self::assertTrue($decoded->equals($anchor));
    }

    #[TestDox('Reads a file anchor without the range, symbol, and snapshot keys')]
    public function testReadsAFileAnchorWithoutTheNullableKeys(): void
    {
        $encoded = ['kind' => 'file', 'path' => 'src/Billing/InvoiceTotals.php', 'commitSha' => self::COMMIT_SHA, 'blobSha' => self::BLOB_SHA];

        self::assertTrue(AnchorCodec::decode(PayloadReader::of($encoded))->equals(Anchor::file(self::path(), self::commitSha(), self::blobSha())));
    }

    /**
     * @return iterable<string, array{array<mixed>, list<string>}>
     */
    public static function corruptAnchors(): iterable
    {
        $valid = self::encodedRangeAnchor();

        yield 'unknown kind' => [['kind' => 'block'] + $valid, ['payload.anchor.kind']];
        yield 'invalid path' => [['path' => '../secret'] + $valid, ['payload.anchor.path']];
        yield 'invalid commit SHA' => [['commitSha' => 'main'] + $valid, ['payload.anchor.commitSha']];
        yield 'upper-case blob SHA' => [['blobSha' => strtoupper(self::BLOB_SHA)] + $valid, ['payload.anchor.blobSha']];
        yield 'end line of the wrong type' => [array_replace_recursive($valid, ['range' => ['endLine' => '6']]), ['payload.anchor.range.endLine']];
        yield 'end line before start line' => [array_replace_recursive($valid, ['range' => ['endLine' => 4]]), ['payload.anchor.range.endLine']];
        yield 'missing start line' => [['range' => ['endLine' => 6]] + $valid, ['payload.anchor.range.startLine']];
        yield 'range without a snapshot' => [['snapshot' => null] + $valid, ['payload.anchor.snapshot']];
        yield 'range with a symbol' => [['symbol' => ['language' => 'php', 'kind' => 'method', 'fqn' => 'f']] + $valid, ['payload.anchor.symbol']];
        yield 'unknown symbol kind' => [['kind' => 'symbol', 'symbol' => ['language' => 'php', 'kind' => 'macro', 'fqn' => 'f']] + $valid, ['payload.anchor.symbol.kind']];
        yield 'number in the snapshot lines' => [array_replace_recursive($valid, ['snapshot' => ['lines' => [1 => 6]]]), ['payload.anchor.snapshot.lines.1']];
        yield 'snapshot of another line count' => [array_replace_recursive($valid, ['snapshot' => ['lineCount' => 3, 'lines' => [2 => 'c']]]), ['payload.anchor.snapshot.lineCount']];
        yield 'invalid snapshot hash' => [array_replace_recursive($valid, ['snapshot' => ['normalizedHash' => 'md5:0']]), ['payload.anchor.snapshot.normalizedHash']];
    }

    /**
     * @param array<mixed> $encoded
     * @param list<string> $expectedFields
     */
    #[DataProvider('corruptAnchors')]
    #[TestDox('Rejects a corrupt stored anchor, naming the dotted field')]
    public function testRejectsACorruptStoredAnchor(array $encoded, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): Anchor => AnchorCodec::decode(PayloadReader::of($encoded, 'payload.anchor'))));
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodedRangeAnchor(): array
    {
        return [
            'kind' => 'range',
            'path' => 'src/Billing/InvoiceTotals.php',
            'commitSha' => self::COMMIT_SHA,
            'blobSha' => self::BLOB_SHA,
            'range' => ['startLine' => 5, 'endLine' => 6],
            'symbol' => null,
            'snapshot' => ['lines' => ['a', 'b'], 'truncated' => false, 'lineCount' => 2, 'before' => [], 'after' => [], 'normalizedHash' => self::HASH],
        ];
    }

    private static function snapshot(int $lineCount): ContentSnapshot
    {
        return ContentSnapshot::fromParts(array_fill(0, $lineCount, 'code'), false, $lineCount, ['}', ''], [''], self::HASH);
    }

    private static function path(): RepoPath
    {
        return RepoPath::fromString('src/Billing/InvoiceTotals.php');
    }

    private static function commitSha(): CommitSha
    {
        return CommitSha::fromString(self::COMMIT_SHA);
    }

    private static function blobSha(): BlobSha
    {
        return BlobSha::fromString(self::BLOB_SHA);
    }
}
