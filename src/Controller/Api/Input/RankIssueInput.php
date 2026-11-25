<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

/**
 * inputs.schema.json#/$defs/RankIssueInput. The action refuses both keys and none.
 */
final readonly class RankIssueInput
{
    public function __construct(
        public ?string $before = null,
        public ?string $after = null,
    ) {
    }
}
