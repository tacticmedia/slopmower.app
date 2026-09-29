<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\SessionUser;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogoutTest extends WebTestCase
{
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $alice = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        $this->client->loginUser(SessionUser::fromUser($alice), 'web');
    }

    #[TestDox('POST /logout with a same-origin proof signs out and redirects to /login')]
    public function testLogoutWithSameOriginProofSignsOut(): void
    {
        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[TestDox('POST /logout from another site gets 403 and keeps the session')]
    public function testLogoutWithoutSameOriginProofFails(): void
    {
        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/v1/me');
        self::assertResponseIsSuccessful();
    }
}
