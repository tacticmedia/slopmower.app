<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Domain\Identity\TenantId;
use App\Entity\User;
use App\MessageHandler\MarketplaceHandler;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * The cancellation job of docs/specs/webhooks-and-live-updates.md section "Marketplace plans". GitHub answers 404 for
 * the account 3877742, Pro for the account 1, and 502 for the account 5.
 */
final class CancelledAccountPurgeTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use GitHubRows;
    use GitHubWebhooks;
    use QueuedMessages;
    use ResetDatabase;

    private const int ACCOUNT_WITHOUT_PLAN = 3877742;

    private const int ACCOUNT_WITH_PLAN = 1;

    private const int ACCOUNT_WITH_FAILING_READ = 5;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime('2027-10-08 00:00:00');
        $this->client = static::createClient();
    }

    #[TestDox('30 days after cancelled, the job purges a tenant whose account has no plan and that has no installation left')]
    public function testPurgesTheTenantAfterThirtyDays(): void
    {
        $user = $this->cancelledUser(self::ACCOUNT_WITHOUT_PLAN);
        self::assertTrue(self::tenantExists($user->getTenantId()), 'The job waits 30 days.');

        self::mockTime('2027-11-07 00:00:01');
        self::runQueuedMessages();

        self::assertFalse(self::tenantExists($user->getTenantId()));
    }

    #[TestDox('A purchase in the 30 days stops the purge')]
    public function testPurchaseStopsThePurge(): void
    {
        $user = $this->cancelledUser(self::ACCOUNT_WITH_PLAN);

        self::mockTime('2027-11-07 00:00:01');
        self::runQueuedMessages();

        self::assertTrue(self::tenantExists($user->getTenantId()));
        self::assertSame([1313], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT plan_id FROM marketplace_account'), 'The job read the plan again.');
    }

    #[TestDox('An installation of an organization stops the purge')]
    public function testOrganizationInstallationStopsThePurge(): void
    {
        $user = $this->cancelledUser(self::ACCOUNT_WITHOUT_PLAN);
        self::storeInstallation($user->getTenantId());

        self::mockTime('2027-11-07 00:00:01');
        self::runQueuedMessages();

        self::assertTrue(self::tenantExists($user->getTenantId()));
    }

    #[TestDox('A failed plan read keeps the tenant, and Messenger retries the job after one hour without a limit')]
    public function testFailedReadRetriesAfterAnHour(): void
    {
        $user = $this->cancelledUser(self::ACCOUNT_WITH_FAILING_READ);
        self::mockTime('2027-11-07 00:00:01');

        try {
            self::runQueuedMessages();
            self::fail('The job did not fail.');
        } catch (HandlerFailedException $failed) {
            $retry = $failed->getPrevious();
            self::assertInstanceOf(RecoverableMessageHandlingException::class, $retry);
            self::assertSame([MarketplaceHandler::RETRY_DELAY_MILLISECONDS, true], [$retry->getRetryDelay(), $retry->forceRetry()]);
        }

        self::assertTrue(self::tenantExists($user->getTenantId()));
    }

    /**
     * A user of the account, whose `cancelled` delivery has run.
     */
    private function cancelledUser(int $accountId): User
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin('monalisa')->withGitHubUser($accountId)->create();
        self::deliverFixture($this->client, 'marketplace_purchase.cancelled', ['marketplace_purchase' => ['account' => ['id' => $accountId, 'login' => 'monalisa']]]);
        self::runQueuedMessages();

        return $user;
    }

    private static function tenantExists(TenantId $tenantId): bool
    {
        return false !== self::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM tenant WHERE id = :id', ['id' => $tenantId->toRfc4122()]);
    }
}
