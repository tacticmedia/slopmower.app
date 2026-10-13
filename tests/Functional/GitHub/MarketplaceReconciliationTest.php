<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Repository\MarketplaceAccountSql;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sign-in read of docs/specs/webhooks-and-live-updates.md section "Marketplace plans". octocat, account 1, has the
 * installations of acme, account 9919, and of octocat. GitHub answers Pro on a free trial for account 1, and 404 for
 * account 9919.
 */
final class MarketplaceReconciliationTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('At sign-in, a recorded answer replaces the record, and a 404 deletes it')]
    public function testSignInReadsThePlans(): void
    {
        $client = static::createClient();
        $accounts = self::getContainer()->get(MarketplaceAccountSql::class);
        $accounts->replace(1, 1111, 'yearly');
        $accounts->replace(9919, 1313, 'monthly');
        $accounts->replace(4, 1313, 'monthly');
        $client->request('GET', '/connect/github');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);

        $client->request('GET', '/login/check-github', ['code' => 'a1b2c3d4', 'state' => $query['state'] ?? '']);

        self::assertResponseRedirects('http://localhost/');
        self::assertSame(
            [
                [1, 1313, 'monthly', '2026-10-20 00:00:00', 1111, '2026-10-20 00:00:00'],
                [4, 1313, 'monthly', null, null, null],
            ],
            self::getContainer()->get(Connection::class)->fetchAllNumeric('SELECT account_id, plan_id, billing_cycle, trial_ends_at, pending_plan_id, pending_effective_at FROM marketplace_account ORDER BY account_id'),
            'The account 4 belongs to no account of the user.',
        );
    }
}
