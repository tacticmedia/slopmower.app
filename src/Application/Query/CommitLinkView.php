<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RequestId;
use App\Entity\CommitLink;

/**
 * commit-link.schema.json.
 */
final readonly class CommitLinkView
{
    /**
     * @param list<RequestId> $otherRequestIds The other requests of the review linked to the commit
     */
    public function __construct(
        public CommitLink $link,
        public array $otherRequestIds,
    ) {
    }
}
