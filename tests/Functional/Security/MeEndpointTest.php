<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MeEndpointTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    #[TestDox('An anonymous call gets 401 unauthorized with WWW-Authenticate')]
    public function testAnonymousCallGetsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/me');

        self::assertSame(
            ['code' => 'unauthorized', 'message' => 'Authentication is required.', 'details' => null],
            self::assertApiError($client->getResponse(), 401, 'unauthorized'),
        );
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="slopmower"');
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'), 'An anonymous API call starts no session.');
    }

    #[TestDox('A signed-in user gets the user, the tenant, a user caller, a null token, the features, the links, no completed tour, and no project key')]
    public function testSignedInUserGetsTheCaller(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->withLogin('jan')->create();
        $client->loginUser($user, 'web');
        $userId = $user->getId()->toString();

        // DEFAULT_URI is http://localhost, so this host proves that the links come from the request.
        $client->request('GET', '/api/v1/me', server: ['HTTP_HOST' => 'slopmower.example:8443']);

        $me = self::assertApiResponse($client->getResponse(), 200, 'me.schema.json');
        self::assertSame(
            [
                'user' => ['id' => $userId, 'login' => 'jan', 'name' => null, 'avatarUrl' => null, 'signInMethod' => 'local'],
                'tenantId' => $user->getTenantId()->toString(),
                'caller' => ['kind' => 'user', 'userId' => $userId, 'agentName' => null, 'name' => 'jan', 'avatarUrl' => null],
                'token' => null,
                'features' => ['localRepositories' => true, 'githubApp' => null],
                'links' => [
                    'mcpUrl' => 'http://slopmower.example:8443/_mcp',
                    'docsUrl' => 'http://slopmower.example:8443/docs',
                    'skillUrl' => 'http://slopmower.example:8443/docs/skill/SKILL.md',
                ],
                'preferences' => ['completedTours' => []],
                'projectKeys' => [],
            ],
            // TenantPlanTest covers plan, and UsageTest covers usage.
            array_diff_key($me, ['plan' => true, 'usage' => true]),
        );
    }

    #[TestDox('A GitHub user gets the slug and the install URL of the GitHub App')]
    public function testGitHubUserGetsTheGitHubApp(): void
    {
        $client = static::createClient();
        $client->loginUser(TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create(), 'web');

        $client->request('GET', '/api/v1/me');

        self::assertSame(
            ['localRepositories' => true, 'githubApp' => ['slug' => 'slopmower-test', 'installUrl' => 'https://github.com/apps/slopmower-test/installations/new']],
            self::assertApiResponse($client->getResponse(), 200, 'me.schema.json')['features'],
        );
    }

    #[TestDox('A session gets the project keys of its tenant in key order')]
    public function testSessionGetsTheProjectKeysOfItsTenant(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        self::storeProject($user->getTenantId(), 'WEB');
        self::storeProject($user->getTenantId(), 'BILL');
        self::storeProject(TenantFixtures::of(self::getContainer())->withLogin('other')->create()->getTenantId(), 'OTHER');

        $me = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/me'), 200, 'me.schema.json');

        self::assertSame(['BILL', 'WEB'], $me['projectKeys']);
    }

    #[TestDox('A token of one repository gets only the key of the project of its repository')]
    public function testRepositoryTokenGetsTheKeyOfItsProject(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        $alpha = self::storeRepository($user->getTenantId(), 'alpha');
        self::storeProject($user->getTenantId(), 'BILL', repository: $alpha);
        self::storeProject($user->getTenantId(), 'WEB');
        [, $secret] = self::storeToken($user, $alpha);

        $me = self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'), 200, 'me.schema.json');

        self::assertSame(['BILL'], $me['projectKeys']);
    }
}
