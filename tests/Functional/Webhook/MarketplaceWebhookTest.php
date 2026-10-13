<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The `marketplace_purchase` rows of docs/specs/webhooks-and-live-updates.md section 1. The deliveries are built from
 * the published webhook descriptions of GitHub, because no listing exists yet. The account is octocat, ID 1.
 */
final class MarketplaceWebhookTest extends WebTestCase
{
    use GitHubWebhooks;
    use QueuedMessages;
    use ResetDatabase;

    private const string ACCOUNTS_QUERY = 'SELECT account_id, plan_id, billing_cycle, trial_ends_at, pending_plan_id, pending_effective_at FROM marketplace_account';

    /** Team, monthly, on a free trial */
    private const array TEAM_ON_TRIAL = [1, 1111, 'monthly', '2026-10-20 00:00:00', null, null];

    /** Pro, yearly */
    private const array PRO = [1, 1313, 'yearly', null, null, null];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[TestDox('purchased stores the plan of the account')]
    public function testPurchasedStoresThePlan(): void
    {
        $this->deliverAndRun('purchased');

        self::assertSame([self::TEAM_ON_TRIAL], self::accounts());
    }

    #[TestDox('changed replaces the plan and clears the pending change')]
    public function testChangedReplacesThePlan(): void
    {
        $this->deliverAndRun('purchased');
        $this->deliverAndRun('pending_change');

        $this->deliverAndRun('changed');

        self::assertSame([self::PRO], self::accounts());
    }

    #[TestDox('pending_change stores the pending plan and its date, and pending_change_cancelled clears them')]
    public function testPendingChange(): void
    {
        $this->deliverAndRun('changed');

        $this->deliverAndRun('pending_change');
        self::assertSame([[...\array_slice(self::PRO, 0, 4), 1111, '2027-10-08 00:00:00']], self::accounts());

        $this->deliverAndRun('pending_change_cancelled');
        self::assertSame([self::PRO], self::accounts());
    }

    #[TestDox('cancelled deletes the record')]
    public function testCancelledDeletesTheRecord(): void
    {
        $this->deliverAndRun('changed');

        $this->deliverAndRun('cancelled');

        self::assertSame([], self::accounts());
    }

    private function deliverAndRun(string $action): void
    {
        self::assertSame(202, self::deliverFixture($this->client, "marketplace_purchase.{$action}")->getStatusCode());
        self::runQueuedMessages();
    }

    /**
     * @return list<list<mixed>>
     */
    private static function accounts(): array
    {
        return self::getContainer()->get(Connection::class)->fetchAllNumeric(self::ACCOUNTS_QUERY);
    }
}
