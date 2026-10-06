<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of V10. `limit` counts requests without a cursor and events with one.
 */
final readonly class UpdatesCursorQuery implements QueryInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $cursor = null,
        #[Assert\Range(min: 1, max: 500)]
        public int $limit = 100,
        public bool $includeOwn = false,
    ) {
    }
}
