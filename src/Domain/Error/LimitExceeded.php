<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Usage\UsageLimit;

final class LimitExceeded extends DomainError
{
    private const int BYTES_PER_MIB = 1_048_576;

    /**
     * @param int $max     Bytes for the size limits
     * @param int $current The use before the call, in the unit of $max
     */
    public function __construct(
        public readonly UsageLimit $limit,
        private readonly int $max,
        private readonly int $current,
    ) {
        parent::__construct(self::message($limit, $max));
    }

    public function code(): ErrorCode
    {
        return ErrorCode::LimitExceeded;
    }

    /**
     * @return array{limit: UsageLimit, max: int, current: int}
     */
    public function details(): array
    {
        return ['limit' => $this->limit, 'max' => $this->max, 'current' => $this->current];
    }

    private static function message(UsageLimit $limit, int $max): string
    {
        $count = number_format($max);
        $mebibytes = number_format(intdiv($max, self::BYTES_PER_MIB));

        return match ($limit) {
            UsageLimit::Repositories => "The limit of connected repositories of your plan is {$count}. Delete the data of a repository in Settings, then try again.",
            UsageLimit::OpenReviews => "The limit of open reviews of your plan is {$count}. Close a review, then try again.",
            UsageLimit::RequestsPerReview => "The limit of requests per review of your plan is {$count}. Start a new review for more requests.",
            UsageLimit::RepositorySize => "The clone is larger than the limit of your plan of {$mebibytes} MiB per repository (repository_size).",
            UsageLimit::TenantStorage => "The clones of your repositories are larger than the limit of your plan of {$mebibytes} MiB in total (tenant_storage).",
        };
    }
}
