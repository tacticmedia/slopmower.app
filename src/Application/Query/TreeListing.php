<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;
use App\Git\TreeEntry;

final readonly class TreeListing
{
    /**
     * @param string          $dir   '' for the root
     * @param list<TreeEntry> $items
     */
    public function __construct(
        public ReviewId $reviewId,
        public CommitSha $commitSha,
        public string $dir,
        public bool $recursive,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
