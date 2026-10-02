<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;

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
     * The V9 order: by start line, then by FQN in byte order.
     */
    public static function compare(self $a, self $b): int
    {
        return $a->range->startLine <=> $b->range->startLine ?: strcmp($a->fqn, $b->fqn);
    }
}
