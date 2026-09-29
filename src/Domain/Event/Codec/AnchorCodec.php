<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;

/**
 * @phpstan-type EncodedRange array{startLine: int, endLine: int}
 * @phpstan-type EncodedSymbol array{language: string, kind: string, fqn: string}
 * @phpstan-type EncodedSnapshot array{lines: list<string>, truncated: bool, lineCount: int, before: list<string>, after: list<string>, normalizedHash: string}
 * @phpstan-type EncodedAnchor array{kind: string, path: string, commitSha: string, blobSha: string, range: EncodedRange|null, symbol: EncodedSymbol|null, snapshot: EncodedSnapshot|null}
 */
final class AnchorCodec
{
    /**
     * @return EncodedAnchor
     */
    public static function encode(Anchor $anchor): array
    {
        return [
            'kind' => $anchor->kind->value,
            'path' => $anchor->path->toString(),
            'commitSha' => $anchor->commitSha->toString(),
            'blobSha' => $anchor->blobSha->toString(),
            'range' => null === $anchor->range ? null : self::encodeRange($anchor->range),
            'symbol' => null === $anchor->symbol ? null : self::encodeSymbol($anchor->symbol),
            'snapshot' => null === $anchor->snapshot ? null : self::encodeSnapshot($anchor->snapshot),
        ];
    }

    public static function decode(PayloadReader $anchor): Anchor
    {
        return Anchor::fromParts(
            $anchor->enum('kind', AnchorKind::class),
            $anchor->parsed('path', RepoPath::fromString(...)),
            $anchor->commitSha('commitSha'),
            $anchor->parsed('blobSha', BlobSha::fromString(...)),
            self::decodeRange($anchor->nullableObject('range')),
            self::decodeSymbol($anchor->nullableObject('symbol')),
            self::decodeSnapshot($anchor->nullableObject('snapshot')),
            $anchor->path(),
        );
    }

    /**
     * @return EncodedRange
     */
    private static function encodeRange(LineRange $range): array
    {
        return ['startLine' => $range->startLine, 'endLine' => $range->endLine];
    }

    /**
     * @return EncodedSymbol
     */
    private static function encodeSymbol(SymbolRef $symbol): array
    {
        return ['language' => $symbol->language, 'kind' => $symbol->kind->value, 'fqn' => $symbol->fqn];
    }

    /**
     * @return EncodedSnapshot
     */
    private static function encodeSnapshot(ContentSnapshot $snapshot): array
    {
        return [
            'lines' => $snapshot->lines,
            'truncated' => $snapshot->truncated,
            'lineCount' => $snapshot->lineCount,
            'before' => $snapshot->before,
            'after' => $snapshot->after,
            'normalizedHash' => $snapshot->normalizedHash,
        ];
    }

    private static function decodeRange(?PayloadReader $range): ?LineRange
    {
        return null === $range ? null : LineRange::of($range->int('startLine'), $range->int('endLine'), $range->path());
    }

    private static function decodeSymbol(?PayloadReader $symbol): ?SymbolRef
    {
        return null === $symbol ? null : SymbolRef::fromParts(
            $symbol->string('language'),
            $symbol->enum('kind', SymbolKind::class),
            $symbol->string('fqn'),
            $symbol->path(),
        );
    }

    private static function decodeSnapshot(?PayloadReader $snapshot): ?ContentSnapshot
    {
        return null === $snapshot ? null : ContentSnapshot::fromParts(
            $snapshot->stringList('lines'),
            $snapshot->bool('truncated'),
            $snapshot->int('lineCount'),
            $snapshot->stringList('before'),
            $snapshot->stringList('after'),
            $snapshot->string('normalizedHash'),
            $snapshot->path(),
        );
    }
}
