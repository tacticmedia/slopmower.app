<?php

declare(strict_types=1);

namespace App\Anchoring;

use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;

/**
 * request.schema.json `CodeExcerpt`.
 */
final readonly class CodeExcerpt
{
    /**
     * @param list<string> $lines The raw lines startLine to endLine, without line endings
     */
    public function __construct(
        public CommitSha $targetSha,
        public RepoPath $path,
        public int $startLine,
        public int $endLine,
        public array $lines,
        public bool $truncated,
    ) {
    }
}
