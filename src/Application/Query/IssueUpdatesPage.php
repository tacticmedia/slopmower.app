<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * issue.schema.json#/$defs/IssueUpdatesPage.
 */
final readonly class IssueUpdatesPage
{
    /**
     * @param list<IssueSummaryView>                                            $issues
     * @param list<array{issue: IssueSummaryView, entry: array<string, mixed>}> $entries Each entry an IssueEntry of the schema
     */
    public function __construct(
        public int $cursor,
        public bool $hasMore,
        public array $issues,
        public array $entries,
    ) {
    }
}
