<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Application\Query\IssueSort;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssueStatus;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class IssueListQuery implements QueryInput
{
    public function __construct(
        public IssueSort $sort = IssueSort::Rank,
        public ?IssueStatus $status = null,
        public ?IssueCategory $category = null,
        public ?IssuePartyKind $waitingForKind = null,
        #[Assert\Range(min: 1, max: 8760)]
        public ?int $activeWithinHours = null,
        #[Assert\Range(min: 1, max: 8760)]
        public ?int $idleForHours = null,
        public bool $noActivity = false,
        #[Assert\Range(min: 1, max: 200)]
        public int $limit = 50,
        #[Assert\Charset('UTF-8')]
        public ?string $cursor = null,
    ) {
    }
}
