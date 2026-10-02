<?php

declare(strict_types=1);

namespace App\Source;

final readonly class LocalRepositoryCandidate
{
    /**
     * @param string      $directory  The name of the direct child of APP_LOCAL_REPOSITORIES_DIR
     * @param string|null $headBranch Null for a detached HEAD
     * @param string      $realPath   Server-side only, never in a response
     */
    public function __construct(
        public string $directory,
        public bool $bare,
        public ?string $headBranch,
        public string $realPath,
    ) {
    }
}
