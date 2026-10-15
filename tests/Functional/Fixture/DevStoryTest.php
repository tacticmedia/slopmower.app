<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use App\Domain\Repository\SyncState;
use App\Message\CloneRepositoryJob;
use App\Repository\RepositoryRepository;
use App\Story\DevStory;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * The story loads without a transaction, as `make fixtures` loads it.
 */
final class DevStoryTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string LOGIN = 'jan';

    protected function setUp(): void
    {
        $this->setEnvironmentVariable('APP_LOCAL_SIGN_IN', self::LOGIN);
        $this->useTemporaryLocalSources();
    }

    #[TestDox('After dev, GET /api/v1/me with the bearer APP_DEV_API_TOKEN gives 200 as the local user')]
    public function testDevTokenAuthenticatesTheLocalUser(): void
    {
        $client = static::createClient();
        DevStory::load();

        $me = self::assertApiResponse(self::bearerRequest($client, self::environmentValue('APP_DEV_API_TOKEN'), 'GET', '/api/v1/me'), 200, 'me.schema.json');

        self::assertIsArray($me['user']);
        self::assertSame([self::LOGIN, 'local'], [$me['user']['login'], $me['user']['signInMethod']]);
        self::assertIsArray($me['token']);
        self::assertSame([DevStory::TOKEN_NAME, 'all_repositories', null], [$me['token']['name'], $me['token']['scope'], $me['token']['expiresAt']]);
    }

    #[TestDox('A second load keeps one token row with the same ID, one user, and one repository')]
    public function testSecondLoadAddsNoRow(): void
    {
        $this->newSource(self::environmentValue('APP_DEV_REPOSITORY'));
        DevStory::load();
        $tokenIds = self::column('SELECT id FROM api_token');

        self::loadAgain();

        self::assertCount(1, $tokenIds);
        self::assertSame($tokenIds, self::column('SELECT id FROM api_token'));
        self::assertCount(1, self::column('SELECT id FROM app_user'));
        self::assertCount(1, self::column('SELECT id FROM repository'));
    }

    #[TestDox('A revoked token is replaced, and the bearer request gives 200 again')]
    public function testRevokedTokenIsReplaced(): void
    {
        $client = static::createClient();
        DevStory::load();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE api_token SET revoked_at = NOW()');

        self::loadAgain();

        self::assertSame(200, self::bearerRequest($client, self::environmentValue('APP_DEV_API_TOKEN'), 'GET', '/api/v1/me')->getStatusCode());
        self::assertCount(1, self::column('SELECT id FROM api_token'));
    }

    #[TestDox('A local candidate named APP_DEV_REPOSITORY is cloning, and one clone job is queued')]
    public function testConnectsTheDevRepository(): void
    {
        $directory = self::environmentValue('APP_DEV_REPOSITORY');
        $this->newSource($directory);

        DevStory::load();

        $repository = self::getContainer()->get(RepositoryRepository::class)->findLocal($directory);
        self::assertNotNull($repository);
        self::assertSame(SyncState::Cloning, $repository->getSyncState());
        self::assertSame([CloneRepositoryJob::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, [...self::asyncTransport()->getSent()]));
    }

    #[TestDox('Without that candidate, the repository step is skipped with a warning')]
    public function testSkipsTheRepositoryWithoutCandidate(): void
    {
        DevStory::load();

        self::assertSame([], self::column('SELECT id FROM repository'));
        self::assertSame([], self::asyncTransport()->getSent());
        self::assertTrue(self::getContainer()->get('monolog.handler.records')->hasWarningThatContains('Skipped the repository APP_DEV_REPOSITORY'));
    }

    #[TestDox('A malformed APP_DEV_API_TOKEN or an empty APP_LOCAL_SIGN_IN fails the load')]
    #[DataProvider('misconfigurations')]
    public function testRefusesMisconfiguration(string $variable, string $value): void
    {
        $this->setEnvironmentVariable($variable, $value);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("{$variable}:");

        DevStory::load();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misconfigurations(): iterable
    {
        yield 'token with the old hr_ prefix' => ['APP_DEV_API_TOKEN', 'hr_'.str_repeat('a', 43)];
        yield 'empty login' => ['APP_LOCAL_SIGN_IN', ''];
    }

    /**
     * DevStory::load() builds once per test. A second `make fixtures` builds again, with an empty identity map.
     */
    private static function loadAgain(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        DevStory::load()->build();
    }

    private static function environmentValue(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function column(string $sql): array
    {
        return self::getContainer()->get(Connection::class)->fetchFirstColumn($sql);
    }
}
