<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Application\Query\ReviewStateFilter;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReviewListQuery implements QueryInput
{
    public function __construct(
        public ReviewStateFilter $state = ReviewStateFilter::Open,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 50,
        #[Assert\Charset('UTF-8')]
        public ?string $cursor = null,
    ) {
    }
}
