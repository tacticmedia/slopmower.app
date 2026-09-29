<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\SessionUser;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MeEndpointTest extends WebTestCase
{
    use JsonApiAssertions;
    use ResetDatabase;

    #[TestDox('An anonymous call gets 401 unauthorized with WWW-Authenticate and no session cookie')]
    public function testAnonymousCallGets401(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="holistic-review"');
        $body = self::assertJsonMatchesSchema($client->getResponse(), 'error.schema.json');
        self::assertSame('unauthorized', $body['error']['code']);
        self::assertNull($body['error']['details']);
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    #[TestDox('A signed-in user gets only its own user and tenant, a user caller, a null token, the features, and the links')]
    public function testSignedInUserGetsTheMeDocument(): void
    {
        $client = self::createClient();
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $fixtures->localUser('bob');
        $alice = $fixtures->localUser('alice');
        $client->loginUser(SessionUser::fromUser($alice), 'web');

        $client->request('GET', 'https://reviews.example/api/v1/me');

        self::assertResponseIsSuccessful();
        $aliceId = $alice->getId()->toString();
        self::assertSame(
            [
                'user' => ['id' => $aliceId, 'login' => 'alice', 'name' => null, 'avatarUrl' => null, 'signInMethod' => 'local'],
                'tenantId' => $alice->getTenantId()->toString(),
                'caller' => ['kind' => 'user', 'userId' => $aliceId, 'agentName' => null, 'name' => 'alice', 'avatarUrl' => null],
                'token' => null,
                'features' => ['localRepositories' => true, 'githubApp' => null],
                'links' => [
                    'mcpUrl' => 'https://reviews.example/_mcp',
                    'docsUrl' => 'https://reviews.example/docs',
                    'skillUrl' => 'https://reviews.example/docs/skill/SKILL.md',
                ],
            ],
            self::assertJsonMatchesSchema($client->getResponse(), 'me.schema.json'),
        );
    }
}
