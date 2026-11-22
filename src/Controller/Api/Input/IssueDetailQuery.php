<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Http\QueryInput;

final readonly class IssueDetailQuery implements QueryInput
{
    public function __construct(
        public bool $history = false,
    ) {
    }
}
