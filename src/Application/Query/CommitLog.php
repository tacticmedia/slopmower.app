<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RepositoryId;
use App\Git\LogCommit;

/**
 * Schema-shaped: `lists.schema.json#/$defs/CommitLog`. R9.
 */
final readonly class CommitLog
{
    /**
     * @param list<LogCommit> $items
     */
    public function __construct(
        public RepositoryId $repositoryId,
        public string $ref,
        public array $items,
    ) {
    }
}
