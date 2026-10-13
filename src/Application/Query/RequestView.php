<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Anchoring\CodeExcerpt;
use App\Domain\Request\Action;

/**
 * request.schema.json.
 */
final readonly class RequestView
{
    /**
     * @param list<ThreadEntry>     $thread         In seq order
     * @param list<CommitLinkView>  $commits        In linkedAt order
     * @param list<PullRequestView> $pullRequests   One per URL of pullRequestUrls, in its order
     * @param list<Action>          $allowedActions For the caller
     * @param LocationView|null     $location       Null when the clone is gone
     * @param CodeExcerpt|null      $excerpt        Only when the caller asked for context lines
     */
    public function __construct(
        public RequestSummaryView $summary,
        public array $thread,
        public array $commits,
        public array $pullRequests,
        public array $allowedActions,
        public ?LocationView $location,
        public ?CodeExcerpt $excerpt,
    ) {
    }
}
