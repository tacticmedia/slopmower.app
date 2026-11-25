<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of I10. `limit` counts issues without a cursor and entries with one.
 */
final readonly class IssueUpdatesQuery implements QueryInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $cursor = null,
        #[Assert\Range(min: 1, max: 200)]
        public int $limit = 50,
    ) {
    }
}
