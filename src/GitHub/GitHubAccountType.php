<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * The `target_type` values of an installation that the server records.
 */
enum GitHubAccountType: string
{
    case User = 'User';
    case Organization = 'Organization';
}
