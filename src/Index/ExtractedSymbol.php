<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;

/**
 * symbol.schema.json. `range` starts at the doc comment when there is one (D7).
 */
final readonly class ExtractedSymbol
{
    public function __construct(
        public SymbolKind $kind,
        public string $fqn,
        public string $name,
        public ?string $parentFqn,
        public LineRange $range,
        public int $declarationStartLine,
        public ?int $docStartLine,
    ) {
    }

    /**
     * The row guard of every extractor. Null for a row that JSON cannot carry, that symbol.schema.json refuses, or that
     * the `symbol` table cannot store: its text columns hold at most {@see SymbolRef::MAX_FQN_CHARACTERS} characters.
     */
    public static function storable(SymbolKind $kind, string $fqn, string $name, ?string $parentFqn, int $startLine, int $endLine, int $declarationStartLine, ?int $docStartLine): ?self
    {
        foreach ([$fqn, $name, $parentFqn ?? ''] as $text) {
            if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || mb_strlen($text, 'UTF-8') > SymbolRef::MAX_FQN_CHARACTERS) {
                return null;
            }
        }

        if ('' === $name || !str_contains($fqn, $name) || $startLine < 1 || $endLine < $startLine) {
            return null;
        }

        return new self($kind, $fqn, $name, $parentFqn, LineRange::of($startLine, $endLine), $declarationStartLine, $docStartLine);
    }

    /**
     * The V9 order: by start line, then by FQN in byte order.
     */
    public static function compare(self $a, self $b): int
    {
        return $a->range->startLine <=> $b->range->startLine ?: strcmp($a->fqn, $b->fqn);
    }
}
