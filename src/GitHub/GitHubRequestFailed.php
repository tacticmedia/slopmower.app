<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * The message holds no token. A failed fetch stores it in `lastError`.
 */
final class GitHubRequestFailed extends \RuntimeException
{
}
