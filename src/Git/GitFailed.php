<?php

declare(strict_types=1);

namespace App\Git;

/**
 * The message never contains a secret: {@see GitRunner} redacts it.
 */
final class GitFailed extends \RuntimeException
{
}
