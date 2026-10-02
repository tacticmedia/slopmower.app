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
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;

/**
 * The `Anchor` of anchor.schema.json.
 */
final class AnchorCodec
{
    /**
     * @return array<string, mixed>
     */
    public static function encode(Anchor $anchor): array
    {
        $range = $anchor->range;
        $symbol = $anchor->symbol;
        $snapshot = $anchor->snapshot;

        return [
            'kind' => $anchor->kind->value,
            'path' => $anchor->path->toString(),
            'commitSha' => $anchor->commitSha->toString(),
            'blobSha' => $anchor->blobSha->toString(),
            'range' => null === $range ? null : ['startLine' => $range->startLine, 'endLine' => $range->endLine],
            'symbol' => null === $symbol ? null : ['language' => $symbol->language, 'kind' => $symbol->kind->value, 'fqn' => $symbol->fqn],
            'snapshot' => null === $snapshot ? null : [
                'lines' => $snapshot->lines,
                'truncated' => $snapshot->truncated,
                'lineCount' => $snapshot->lineCount,
                'before' => $snapshot->before,
                'after' => $snapshot->after,
                'normalizedHash' => $snapshot->normalizedHash,
            ],
        ];
    }

    /**
     * The value objects name their fields relative to the anchor, so they are all built under the anchor path.
     */
    public static function decode(PayloadReader $anchor): Anchor
    {
        $range = $anchor->nullableObject('range');
        $symbol = $anchor->nullableObject('symbol');
        $snapshot = $anchor->nullableObject('snapshot');

        return $anchor->build(static fn (): Anchor => Anchor::of(
            $anchor->enum('kind', AnchorKind::class),
            RepoPath::fromString($anchor->string('path')),
            CommitSha::fromString($anchor->string('commitSha')),
            BlobSha::fromString($anchor->string('blobSha')),
            null === $range ? null : LineRange::of($range->int('startLine'), $range->int('endLine')),
            null === $symbol ? null : SymbolRef::of($symbol->string('language'), $symbol->enum('kind', SymbolKind::class), $symbol->string('fqn')),
            null === $snapshot ? null : ContentSnapshot::of(
                $snapshot->stringList('lines'),
                $snapshot->bool('truncated'),
                $snapshot->int('lineCount'),
                $snapshot->stringList('before'),
                $snapshot->stringList('after'),
                $snapshot->string('normalizedHash'),
            ),
        ));
    }
}
