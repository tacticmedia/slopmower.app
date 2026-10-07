<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\RepoPath;

/**
 * search-hit.schema.json#/$defs/FileHit.
 */
final readonly class FileHit
{
    public string $kind;

    public function __construct(public RepoPath $path)
    {
        $this->kind = 'file';
    }
}
