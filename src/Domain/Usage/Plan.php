<?php

declare(strict_types=1);

namespace App\Domain\Usage;

/**
 * A plan of the instance: the values of the usage limits that it gives. Of several plans, the higher rank wins.
 */
final readonly class Plan
{
    public const string FREE = 'free';

    public const string FREE_NAME = 'Free';

    private const int BYTES_PER_MIB = 1_048_576;

    /**
     * @param array{repositories: int, open_reviews: int, requests_per_review: int, repository_size_mib: int, tenant_storage_mib: int} $limits 0 is off
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $rank,
        private array $limits,
    ) {
    }

    /**
     * @return int|null Null when the limit is off. Bytes for the size limits.
     */
    public function max(UsageLimit $limit): ?int
    {
        $max = match ($limit) {
            UsageLimit::Repositories => $this->limits['repositories'],
            UsageLimit::OpenReviews => $this->limits['open_reviews'],
            UsageLimit::RequestsPerReview => $this->limits['requests_per_review'],
            UsageLimit::RepositorySize => $this->limits['repository_size_mib'] * self::BYTES_PER_MIB,
            UsageLimit::TenantStorage => $this->limits['tenant_storage_mib'] * self::BYTES_PER_MIB,
        };

        return $max > 0 ? $max : null;
    }
}
