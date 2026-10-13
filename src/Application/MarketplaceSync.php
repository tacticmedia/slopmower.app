<?php

declare(strict_types=1);

namespace App\Application;

use App\GitHub\GitHubRequestFailed;
use App\GitHub\InstallationTokenProvider;
use App\GitHub\Payload\MarketplaceAccountPayload;
use App\Repository\MarketplaceAccountSql;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the Marketplace plan of a GitHub account again, docs/specs/webhooks-and-live-updates.md section "Marketplace
 * plans": at sign-in and in the cancellation job.
 */
final class MarketplaceSync
{
    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly InstallationTokenProvider $installationTokens,
        private readonly DenormalizerInterface $denormalizer,
        private readonly MarketplaceAccountSql $accounts,
    ) {
    }

    /**
     * Replaces the record of the account with the answer of GitHub, or deletes it when GitHub answers 404.
     *
     * @return bool Whether the account has a plan
     *
     * @throws GitHubRequestFailed The record is unchanged
     */
    public function syncAccount(int $accountId): bool
    {
        $path = "marketplace_listing/accounts/{$accountId}";

        try {
            $response = $this->githubClient->request('GET', InstallationTokenProvider::API.$path, [
                'auth_bearer' => $this->installationTokens->appJwt(),
                'headers' => InstallationTokenProvider::API_HEADERS,
            ]);

            if (404 === $response->getStatusCode()) {
                $this->accounts->delete($accountId);

                return false;
            }

            $account = $this->denormalizer->denormalize($response->toArray(), MarketplaceAccountPayload::class);
        } catch (HttpClientException|SerializerException $failure) {
            throw new GitHubRequestFailed("GitHub answered GET /{$path} with a failure or an object of an unexpected shape.", 0, $failure);
        }

        if ($accountId !== $account->id) {
            throw new GitHubRequestFailed("GitHub answered GET /{$path} with another account.");
        }

        $purchase = $account->purchase;
        $this->accounts->replace($accountId, $purchase->planId, $purchase->billingCycle, $purchase->trialEndsAt(), $account->pendingPlanId, $account->pendingEffectiveAt);

        return true;
    }
}
