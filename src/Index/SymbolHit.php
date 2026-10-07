<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\SymbolKind;
use App\Domain\Git\RepoPath;

/**
 * search-hit.schema.json#/$defs/SymbolHit.
 */
final readonly class SymbolHit
{
    public string $kind;

    public function __construct(
        public RepoPath $path,
        public string $fqn,
        public SymbolKind $symbolKind,
        public int $declarationStartLine,
    ) {
        $this->kind = 'symbol';
    }
}
