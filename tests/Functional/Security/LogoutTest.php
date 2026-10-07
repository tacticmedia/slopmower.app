<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class LogoutTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('POST /logout without a same-origin proof gets 403, and with one ends the session')]
    public function testLogoutNeedsSameOriginProof(): void
    {
        $client = static::createClient();
        $client->loginUser(TenantFixtures::of(self::getContainer())->create(), 'web');

        $client->request('POST', '/logout', ['_csrf_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('http://localhost/login');

        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[TestDox('After a double-submit sign-out, a same-origin form in the new session passes the CSRF check')]
    public function testSignOutForgetsTheCsrfStrategyOfTheOldSession(): void
    {
        $client = static::createClient();
        $client->loginUser(TenantFixtures::of(self::getContainer())->create(), 'web');
        $doubleSubmitToken = bin2hex(random_bytes(16));
        $client->getCookieJar()->set(new Cookie('csrf-token_'.$doubleSubmitToken, 'csrf-token'));

        $client->request('POST', '/logout', ['_csrf_token' => $doubleSubmitToken]);
        self::assertResponseRedirects('http://localhost/login');

        // The sign-in form is dev-only. A second sign-out is another same-origin form in the new session.
        $client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('http://localhost/login');
    }
}
