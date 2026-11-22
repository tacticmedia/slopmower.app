<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * issue.schema.json#/$defs/IssueDetail.
 */
final readonly class IssueDetail
{
    /**
     * @param list<array<string, mixed>> $links  Each an IssueLink of the schema
     * @param list<array<string, mixed>> $thread Each an IssueEntry of the schema
     */
    public function __construct(
        public IssueView $issue,
        public array $links,
        public array $thread,
    ) {
    }
}
