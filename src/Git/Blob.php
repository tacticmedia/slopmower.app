<?php

declare(strict_types=1);

namespace App\Git;

/**
 * A file or symlink at a path of a commit. The content of a symlink is its target.
 */
final readonly class Blob
{
    public function __construct(
        public TreeEntry $entry,
        public BlobContent $content,
    ) {
    }
}
