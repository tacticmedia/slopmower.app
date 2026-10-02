<?php

declare(strict_types=1);

namespace App\Source;

final readonly class LocalRepositoryListing
{
    /**
     * @param list<LocalRepositoryCandidate> $candidates Sorted by directory
     * @param bool                           $truncated  More candidates exist than the listing holds
     */
    public function __construct(
        public array $candidates,
        public bool $truncated,
    ) {
    }
}
