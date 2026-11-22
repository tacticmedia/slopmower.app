<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Issue\ApprovalMode;

/**
 * project.schema.json.
 */
final readonly class ProjectView
{
    /**
     * @param list<string> $repositories
     */
    public function __construct(
        public string $key,
        public string $name,
        public ApprovalMode $approvalMode,
        public array $repositories,
    ) {
    }
}
