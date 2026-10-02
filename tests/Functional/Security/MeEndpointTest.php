<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MeEndpointTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('An anonymous call gets 401 unauthorized with WWW-Authenticate')]
    public function testAnonymousCallGetsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="holistic-review"');
        self::assertSame(
            ['error' => ['code' => 'unauthorized', 'message' => 'Authentication is required.', 'details' => null]],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'), 'An anonymous API call starts no session.');
    }

    #[TestDox('A signed-in user gets the user, the tenant, a user caller, a null token, the features, and the links')]
    public function testSignedInUserGetsTheCaller(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->withLogin('jan')->create();
        $client->loginUser($user, 'web');
        $userId = $user->getId()->toString();

        // DEFAULT_URI is http://localhost, so this host proves that the links come from the request.
        $client->request('GET', '/api/v1/me', server: ['HTTP_HOST' => 'hr.example:8443']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'user' => ['id' => $userId, 'login' => 'jan', 'name' => null, 'avatarUrl' => null, 'signInMethod' => 'local'],
                'tenantId' => $user->getTenantId()->toString(),
                'caller' => ['kind' => 'user', 'userId' => $userId, 'agentName' => null, 'name' => 'jan', 'avatarUrl' => null],
                'token' => null,
                'features' => ['localRepositories' => true, 'githubApp' => null],
                'links' => [
                    'mcpUrl' => 'http://hr.example:8443/_mcp',
                    'docsUrl' => 'http://hr.example:8443/docs',
                    'skillUrl' => 'http://hr.example:8443/docs/skill/SKILL.md',
                ],
            ],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
