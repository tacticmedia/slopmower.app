<?php

declare(strict_types=1);

namespace App\Application;

/**
 * The source of a repository cannot be fetched. The message becomes `sync.lastError`.
 */
final class SourceUnavailable extends \RuntimeException
{
}
