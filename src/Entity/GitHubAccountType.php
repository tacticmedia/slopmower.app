<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The `target_type` values of an installation that the server records.
 */
enum GitHubAccountType: string
{
    case User = 'User';
    case Organization = 'Organization';
}
