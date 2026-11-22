<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\IssueStatus;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class IssueListQuery implements QueryInput
{
    public function __construct(
        public ?IssueStatus $status = null,
        #[Assert\Range(min: 1, max: 200)]
        public int $limit = 50,
        #[Assert\Charset('UTF-8')]
        public ?string $cursor = null,
    ) {
    }
}
