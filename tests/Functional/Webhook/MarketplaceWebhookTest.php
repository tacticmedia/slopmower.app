<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Repository\MarketplaceAccountSql;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The `marketplace_purchase` rows of docs/specs/webhooks-and-live-updates.md section 1. The deliveries are built from
 * the published webhook descriptions of GitHub, because no listing exists yet. The account is octocat, ID 1. The
 * handler reads the account from GitHub whatever the action (D97).
 */
final class MarketplaceWebhookTest extends WebTestCase
{
    use GitHubWebhooks;
    use QueuedMessages;
    use ResetDatabase;

    private const string ACCOUNTS_QUERY = 'SELECT account_id, plan_id, billing_cycle, trial_ends_at, pending_plan_id, pending_effective_at FROM marketplace_account';

    /** Pro, monthly, on a free trial, with a pending change to Team: the account as GitHub reads it. */
    private const array READ_ACCOUNT = [1, 1313, 'monthly', '2026-10-20 00:00:00', 1111, '2026-10-20 00:00:00'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param list<list<mixed>> $before
     */
    #[TestDox('Each action stores the account as GitHub reads it, also in another order than GitHub sent it')]
    #[DataProvider('actions')]
    public function testEachActionStoresTheReadAccount(string $action, array $before): void
    {
        foreach ($before as $row) {
            self::getContainer()->get(MarketplaceAccountSql::class)->replace(...$row);
        }

        $this->deliverAndRun($action);

        self::assertSame([self::READ_ACCOUNT], self::accounts());
    }

    /**
     * @return iterable<string, array{string, list<list<mixed>>}>
     */
    public static function actions(): iterable
    {
        yield 'purchased' => ['purchased', []];
        yield 'changed after a later pending change' => ['changed', [[1, 1313, 'yearly']]];
        yield 'pending_change' => ['pending_change', [[1, 1313, 'monthly']]];
        yield 'pending_change_cancelled' => ['pending_change_cancelled', [[1, 1313, 'monthly', null, 1111, new \DateTimeImmutable('2026-10-20')]]];
        yield 'cancelled' => ['cancelled', [[1, 1313, 'monthly']]];
    }

    #[TestDox('An old purchased that arrives after the cancellation deletes the record, because GitHub finds no plan')]
    public function testLatePurchaseOfACancelledAccountStoresNothing(): void
    {
        self::getContainer()->get(MarketplaceAccountSql::class)->replace(2, 1313, 'monthly');

        $this->deliverAndRun('purchased', ['marketplace_purchase' => ['account' => ['id' => 2]]]);

        self::assertSame([], self::accounts());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function deliverAndRun(string $action, array $overrides = []): void
    {
        self::assertSame(202, self::deliverFixture($this->client, "marketplace_purchase.{$action}", $overrides)->getStatusCode());
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
