<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Git\RepoPath;

/**
 * declaration.schema.json.
 */
final readonly class Declaration
{
    public function __construct(
        public RepoPath $path,
        public string $fqn,
        public SymbolKind $kind,
        public LineRange $range,
        public int $declarationStartLine,
    ) {
    }

    /**
     * The V14 order: by path in byte order, then by start line, then by FQN.
     */
    public static function compare(self $a, self $b): int
    {
        return strcmp($a->path->toString(), $b->path->toString()) ?: $a->range->startLine <=> $b->range->startLine ?: strcmp($a->fqn, $b->fqn);
    }
}
