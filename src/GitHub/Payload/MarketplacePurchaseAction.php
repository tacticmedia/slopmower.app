<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

/**
 * The actions of the `marketplace_purchase` webhook.
 */
enum MarketplacePurchaseAction: string
{
    case Purchased = 'purchased';
    case Changed = 'changed';
    case PendingChange = 'pending_change';
    case PendingChangeCancelled = 'pending_change_cancelled';
    case Cancelled = 'cancelled';
}
