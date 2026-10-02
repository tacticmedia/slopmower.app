<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;

/**
 * Text fields have invalid UTF-8 replaced, because they go into JSON.
 */
final readonly class CommitDetails
{
    /**
     * @param CommitSha|null $parentSha The first parent, null for a root commit
     */
    public function __construct(
        public CommitSha $sha,
        public ?CommitSha $parentSha,
        public string $subject,
        public string $message,
        public string $authorName,
        public string $authorEmail,
        public \DateTimeImmutable $committedAt,
    ) {
    }
}
