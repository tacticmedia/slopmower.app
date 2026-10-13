<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\MarketplaceSync;
use App\GitHub\GitHubRequestFailed;
use App\GitHub\Payload\MarketplacePurchaseAction;
use App\Message\MarketplaceCancellationJob;
use App\Message\MarketplacePurchaseJob;
use App\Repository\GitHubInstallationRepository;
use App\Repository\MarketplaceAccountSql;
use App\Tenancy\TenantContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * The `marketplace_purchase` deliveries and the cancellation job of docs/specs/webhooks-and-live-updates.md section
 * "Marketplace plans".
 */
final class MarketplaceHandler
{
    public const int RETRY_DELAY_MILLISECONDS = 3_600_000;

    public function __construct(
        private readonly MarketplaceAccountSql $accounts,
        private readonly MarketplaceSync $marketplace,
        private readonly GitHubInstallationRepository $installations,
        private readonly DeletionHandler $deletion,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[AsMessageHandler]
    public function handlePurchase(MarketplacePurchaseJob $job): void
    {
        switch ($job->action) {
            case MarketplacePurchaseAction::Purchased:
            case MarketplacePurchaseAction::Changed:
                $this->accounts->replace($job->accountId, $job->purchase->planId, $job->purchase->billingCycle, $job->purchase->trialEndsAt());
                break;
            case MarketplacePurchaseAction::PendingChange:
                $this->accounts->recordPendingChange($job->accountId, $job->purchase->planId, $job->effectiveAt);
                break;
            case MarketplacePurchaseAction::PendingChangeCancelled:
                $this->accounts->recordPendingChange($job->accountId, null, null);
                break;
            case MarketplacePurchaseAction::Cancelled:
                $this->accounts->delete($job->accountId);
                break;
        }
    }

    /**
     * Purges the tenant of the job when the account has no plan and the tenant has no installation left. The count runs
     * under the tenant row lock of the purge, which every installation write holds too.
     *
     * @throws RecoverableMessageHandlingException The plan read failed: Messenger retries the job every hour, without a
     *                                             limit, so the purge is decided only on an answer of GitHub
     */
    #[AsMessageHandler]
    public function handleCancellation(MarketplaceCancellationJob $job): void
    {
        try {
            $hasPlan = $this->marketplace->syncAccount($job->accountId);
        } catch (GitHubRequestFailed $failure) {
            throw new RecoverableMessageHandlingException("The Marketplace plan of GitHub account {$job->accountId} could not be read.", 0, $failure, self::RETRY_DELAY_MILLISECONDS, forceRetry: true);
        }

        if (!$hasPlan) {
            $this->deletion->purge($this->tenantContext->get(), fn (): bool => 0 < $this->installations->count());
        }
    }
}
