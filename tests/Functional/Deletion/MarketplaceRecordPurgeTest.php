<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Entity\User;
use App\Repository\MarketplaceAccountSql;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Marketplace records in the purge of the job that A2 queues. The user "monalisa" has the GitHub account 3877742,
 * and her tenant has an installation of the organization 9919. Both accounts have a record.
 */
final class MarketplaceRecordPurgeTest extends WebTestCase
{
    use ApiFixtures;
    use GitHubRows;
    use QueuedMessages;
    use ResetDatabase;

    private const int USER_ACCOUNT = 3877742;

    private const int ORGANIZATION_ACCOUNT = 9919;

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = TenantFixtures::of(self::getContainer())->withLogin('monalisa')->withGitHubUser(self::USER_ACCOUNT)->create();
        self::storeInstallation($this->user->getTenantId(), accountId: self::ORGANIZATION_ACCOUNT);
        $accounts = self::getContainer()->get(MarketplaceAccountSql::class);
        $accounts->replace(self::USER_ACCOUNT, 1313, 'monthly');
        $accounts->replace(self::ORGANIZATION_ACCOUNT, 1313, 'monthly');
    }

    #[TestDox('An account deletion deletes the record of the GitHub account of the user and keeps the record of the organization')]
    public function testDeletesTheRecordOfTheUserAccount(): void
    {
        $this->deleteAccountAndRunThePurge();

        self::assertSame([self::ORGANIZATION_ACCOUNT], self::recordedAccounts());
    }

    #[TestDox('An account deletion keeps the record of the user account when another tenant has it as an installation account')]
    public function testKeepsTheRecordOfAnAccountThatAnotherTenantInstalled(): void
    {
        $other = TenantFixtures::of(self::getContainer())->withLogin('hubot')->withGitHubUser(583231)->create();
        self::storeInstallation($other->getTenantId(), 5552, 'monalisa', accountId: self::USER_ACCOUNT);

        $this->deleteAccountAndRunThePurge();

        self::assertSame([self::ORGANIZATION_ACCOUNT, self::USER_ACCOUNT], self::recordedAccounts());
    }

    private function deleteAccountAndRunThePurge(): void
    {
        $this->client->loginUser($this->user, 'web');
        self::assertSame(202, self::apiRequest($this->client, 'DELETE', '/api/v1/account?confirm=monalisa')->getStatusCode());
        self::runQueuedMessages();
        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM tenant WHERE id = :id', ['id' => $this->user->getTenantId()->toRfc4122()]), 'The purge ran.');
    }

    /**
     * @return list<int>
     */
    private static function recordedAccounts(): array
    {
        return array_map(intval(...), self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT account_id FROM marketplace_account ORDER BY account_id'));
    }
}
