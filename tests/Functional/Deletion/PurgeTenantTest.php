<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Entity\User;
use App\Git\TenantDataPaths;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Deletion\DeletionFixtures;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The purge of the job that A2 queues, and of `app:tenant:purge`. The user "jan" has a row in every table with a
 * tenant_id column, and a clone. The user "other" of another tenant has a review of the same source.
 */
final class PurgeTenantTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use DeletionFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $commit = $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->other = self::signIn($this->client, 'other');
        $otherReview = (string) self::startReviewThroughApi($this->client, self::connectAndClone($this->client))['id'];
        self::runQueuedMessages();
        self::openRequest($this->client, $otherReview);
        self::storeToken($this->other);
        $this->user = self::signIn($this->client);
        $repositoryId = self::connectAndClone($this->client);
        [, $secret] = self::storeToken($this->user);
        self::populatedReview($this->client, $this->user, self::secondUser($this->user), $repositoryId, $secret, $commit);
        self::storePullRequest($repositoryId);
        self::storeInstallation($this->user->getTenantId());
        self::storeCandidate($this->user->getTenantId());
    }

    #[TestDox('information_schema shows 0 rows of the tenant in every table with tenant_id')]
    public function testNoRowOfTheTenantRemains(): void
    {
        $before = self::tenantRowCounts($this->user->getTenantId());

        $this->deleteAccountAndRunThePurge();

        self::assertNotContains(0, $before, 'Each table has a row of the tenant.');
        self::assertSame(array_fill_keys(array_keys($before), 0), self::tenantRowCounts($this->user->getTenantId()));
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM tenant'), 'The tenant row is gone too.');
    }

    #[TestDox('The tenant directory is gone')]
    public function testTenantDirectoryIsGone(): void
    {
        $tenantDirectory = self::getContainer()->get(TenantDataPaths::class)->tenantPath($this->user->getTenantId());
        $existedBefore = is_dir($tenantDirectory);

        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:tenant:purge'));
        $tester->execute(['tenant-id' => $this->user->getTenantId()->toString()]);

        self::assertTrue($existedBefore);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($tenantDirectory);
        self::assertSame(0, array_sum(self::tenantRowCounts($this->user->getTenantId())));
    }

    #[TestDox('A second purge deletes a row that a writer left after the first, without the tenant row')]
    public function testSecondPurgeDeletesLeftRows(): void
    {
        $this->deleteAccountAndRunThePurge();
        self::storeCandidate($this->user->getTenantId(), 812399, 'acme/late');

        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:tenant:purge'));
        $tester->execute(['tenant-id' => $this->user->getTenantId()->toString()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, array_sum(self::tenantRowCounts($this->user->getTenantId())));
    }

    #[TestDox('The other tenant is untouched')]
    public function testOtherTenantIsUntouched(): void
    {
        $before = self::tenantRowCounts($this->other->getTenantId());

        $this->deleteAccountAndRunThePurge();

        self::assertSame($before, self::tenantRowCounts($this->other->getTenantId()));
        self::assertDirectoryExists(self::getContainer()->get(TenantDataPaths::class)->tenantPath($this->other->getTenantId()));
        $this->client->loginUser($this->other, 'web');
        self::assertSame(1, self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories'), 200, 'lists.schema.json#/$defs/RepositoryPage')['items'][0]['openReviewCount'] ?? null);
    }

    private function deleteAccountAndRunThePurge(): void
    {
        self::assertSame(202, self::apiRequest($this->client, 'DELETE', '/api/v1/account?confirm=jan')->getStatusCode());
        self::runQueuedMessages();
    }
}
