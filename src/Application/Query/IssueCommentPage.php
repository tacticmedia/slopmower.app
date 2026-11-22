<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * lists.schema.json#/$defs/IssueCommentPage.
 */
final readonly class IssueCommentPage
{
    /**
     * @param list<array<string, mixed>> $items Each an IssueComment of issue.schema.json
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
