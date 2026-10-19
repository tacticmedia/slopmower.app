<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of R9. The ref rules are those of {@see \App\Domain\Git\RefName}.
 */
final readonly class CommitListQuery implements QueryInput
{
    public function __construct(
        public ?string $ref = null,
        #[Assert\Range(min: 1, max: 50)]
        public int $limit = 10,
    ) {
    }
}
