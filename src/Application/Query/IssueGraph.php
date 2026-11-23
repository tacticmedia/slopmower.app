<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Issue\IssueKey;

/**
 * issue.schema.json#/$defs/IssueGraph.
 */
final readonly class IssueGraph
{
    /**
     * @param list<IssueSummaryView>                            $issues
     * @param list<array{issue: IssueKey, blockedBy: IssueKey}> $links
     */
    public function __construct(
        public array $issues,
        public array $links,
        public int $otherOpenIssues,
    ) {
    }
}
