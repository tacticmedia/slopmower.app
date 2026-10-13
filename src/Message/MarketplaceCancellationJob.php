<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Queued 30 days ahead at `cancelled` of the user account of the tenant.
 */
#[AsMessage('async')]
final readonly class MarketplaceCancellationJob
{
    public const string DELAY = 'P30D';

    public function __construct(public int $accountId)
    {
    }
}
