<?php

declare(strict_types=1);

namespace App\Domain\Usage;

/**
 * The usage limits of a plan, docs/specs/README.md section "Usage limits". Sizes are in bytes.
 */
enum UsageLimit: string
{
    case Repositories = 'repositories';
    case OpenReviews = 'open_reviews';
    case RequestsPerReview = 'requests_per_review';
    case RepositorySize = 'repository_size';
    case TenantStorage = 'tenant_storage';
}
