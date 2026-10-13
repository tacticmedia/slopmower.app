<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Request\PullRequestState;
use App\Domain\Request\PullRequestUrl;

/**
 * `PullRequest` of request.schema.json.
 */
final readonly class PullRequestView
{
    public function __construct(
        public PullRequestUrl $url,
        public PullRequestState $state,
        public ?string $title,
    ) {
    }
}
