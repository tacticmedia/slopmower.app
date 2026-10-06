<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Repository\RepositorySource;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RepositoryListQuery implements QueryInput
{
    public function __construct(
        #[Assert\Charset('UTF-8')]
        public ?string $name = null,
        public ?RepositorySource $source = null,
        #[Assert\Range(min: 1, max: 500)]
        public int $limit = 100,
        #[Assert\Charset('UTF-8')]
        public ?string $cursor = null,
    ) {
    }
}
