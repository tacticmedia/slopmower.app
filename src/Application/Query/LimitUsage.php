<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Usage\UsageLimit;

/**
 * An item of `usage` in me.schema.json.
 */
final readonly class LimitUsage
{
    /**
     * @param int|null $max Null when the limit is off
     */
    public function __construct(
        public UsageLimit $limit,
        public ?int $max,
        public int $current,
    ) {
    }
}
