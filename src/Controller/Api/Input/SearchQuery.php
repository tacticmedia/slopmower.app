<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of V16.
 */
final readonly class SearchQuery implements QueryInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(min: 1, max: 1024)]
        #[Assert\Regex(pattern: '/^[^\x00]*$/D', message: 'Must not hold a NUL byte.')]
        public ?string $q = null,
        #[Assert\Range(min: 1, max: 50)]
        public int $limit = 20,
    ) {
    }
}
