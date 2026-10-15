<?php

declare(strict_types=1);

namespace App\Tests\Support\Sentry;

use Sentry\EventHint;
use Sentry\EventId;
use Sentry\State\Hub;

/**
 * The Sentry hub of the test environment, where the Sentry bundle is off. Keeps each captured exception.
 */
final class RecordingHub extends Hub
{
    /** @var list<\Throwable> */
    public private(set) array $exceptions = [];

    public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
    {
        $this->exceptions[] = $exception;

        return null;
    }
}
