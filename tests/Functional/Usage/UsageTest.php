<?php

declare(strict_types=1);

namespace App\Tests\Functional\Usage;

use App\Domain\Identity\RepositoryId;
use App\Entity\Repository;
use App\Entity\User;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `usage` of A1, docs/specs/rest-api.md.
 */
final class UsageTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const int MIB = 1_048_576;

    private KernelBrowser $client;

    private User $user;

    /** @var list<string> */
    private array $repositoryIds;

    protected function setUp(): void
    {
        $this->setEnvironmentVariable('APP_PLAN_FREE_REPOSITORIES', '3');
        $this->setEnvironmentVariable('APP_PLAN_FREE_OPEN_REVIEWS', '4');
        $this->setEnvironmentVariable('APP_PLAN_FREE_REQUESTS_PER_REVIEW', '5');
        $this->setEnvironmentVariable('APP_PLAN_FREE_REPOSITORY_SIZE_MIB', '6');
        $this->setEnvironmentVariable('APP_PLAN_FREE_TENANT_STORAGE_MIB', '0');
        $this->useTemporaryLocalSources();
        $this->newSource('billing');
        $this->newSource('shipping')->commit(['notes.txt' => str_repeat("Ship it.\n", 1000)]);
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryIds = [self::connectAndClone($this->client, 'billing'), self::connectAndClone($this->client, 'shipping')];
        $billingReviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryIds[0])['id'];
        $shippingReviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryIds[1])['id'];
        self::openRequest($this->client, $billingReviewId);
        self::openRequest($this->client, $billingReviewId);
        self::openRequest($this->client, $shippingReviewId);
    }

    #[TestDox('Me.usage lists each limit with its current use, and a limit set to zero has max null')]
    public function testListsTheUseOfEachLimit(): void
    {
        $sizesBytes = $this->cloneSizesBytes();

        $usage = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/me'), 200, 'me.schema.json')['usage'];

        self::assertSame([
            ['limit' => 'repositories', 'max' => 3, 'current' => 2],
            ['limit' => 'open_reviews', 'max' => 4, 'current' => 2],
            ['limit' => 'requests_per_review', 'max' => 5, 'current' => 2],
            ['limit' => 'repository_size', 'max' => 6 * self::MIB, 'current' => max($sizesBytes)],
            ['limit' => 'tenant_storage', 'max' => null, 'current' => array_sum($sizesBytes)],
        ], $usage);
    }

    #[TestDox('A token scoped to one repository gets the use of the whole tenant')]
    public function testScopedTokenGetsTheUseOfTheTenant(): void
    {
        $session = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/me'), 200, 'me.schema.json')['usage'];
        [, $secret] = self::storeToken($this->user, self::storedRepository($this->repositoryIds[0]));

        $scoped = self::assertApiResponse(self::bearerRequest($this->client, $secret, 'GET', '/api/v1/me'), 200, 'me.schema.json')['usage'];

        self::assertSame($session, $scoped);
    }

    private static function storedRepository(string $id): Repository
    {
        return self::getContainer()->get(TenantFilter::class)->suspend(
            static fn (): ?Repository => self::getContainer()->get(RepositoryRepository::class)->findFresh(RepositoryId::fromString($id)),
        ) ?? self::fail("The repository {$id} does not exist.");
    }

    /**
     * @return array{int, int} Of billing and shipping
     */
    private function cloneSizesBytes(): array
    {
        $sizesBytes = [(int) self::storedRepository($this->repositoryIds[0])->getCloneSizeBytes(), (int) self::storedRepository($this->repositoryIds[1])->getCloneSizeBytes()];
        self::assertGreaterThan($sizesBytes[0], $sizesBytes[1], 'The shipping clone is the larger one.');

        return $sizesBytes;
    }
}
