<?php

declare(strict_types=1);

namespace App\Tests\Functional\Live;

use App\Security\CurrentActor;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Cookie;

final class MercureAuthorizeTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use JsonApiAssertions;
    use ResetDatabase;

    private const string PATH = '/api/v1/mercure/authorize';

    #[TestDox('Sets the subscriber cookie with HttpOnly, SameSite Strict, and the hub path')]
    public function testSetsTheSubscriberCookie(): void
    {
        $client = static::createClient();
        self::signIn($client);

        $cookie = self::authorize($client);

        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertNull($cookie->getDomain());
    }

    #[TestDox('Adds Secure only when the request uses HTTPS')]
    public function testSecureFollowsTheRequestScheme(): void
    {
        $client = static::createClient();
        self::signIn($client);

        self::assertFalse(self::authorize($client)->isSecure());
        self::assertTrue(self::authorize($client, ['HTTPS' => 'on'])->isSecure());
    }

    #[TestDox("The JWT subscribe claim holds exactly the tenant's two selectors and expires in one hour")]
    public function testJwtHoldsTheTenantSelectors(): void
    {
        self::mockTime('2026-09-29T07:31:12Z');
        $client = static::createClient();
        $tenantId = self::signIn($client)->getTenantId();
        $selectors = ["http://localhost/tenants/{$tenantId}/repositories", "http://localhost/tenants/{$tenantId}/reviews/{reviewId}"];

        $token = self::parse(self::authorize($client)->getValue() ?? '');

        self::assertSame(['subscribe' => $selectors], $token->claims()->get('mercure'));
        self::assertEquals(new \DateTimeImmutable('2026-09-29T08:31:12Z'), $token->claims()->get('exp'));
        self::assertSame(
            ['hubUrl' => 'http://localhost:8081/.well-known/mercure', 'topics' => $selectors, 'expiresAt' => '2026-09-29T08:31:12Z'],
            self::assertApiResponse($client->getResponse(), 200, 'lists.schema.json#/$defs/MercureAuthorization'),
        );
    }

    #[TestDox('A bearer caller gets 403 forbidden')]
    public function testBearerCallerIsForbidden(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(self::signIn($client));
        $client->getCookieJar()->clear();

        $response = self::bearerRequest($client, $secret, 'POST', self::PATH);

        self::assertSame(CurrentActor::SESSION_ONLY, self::assertApiError($response, 403, 'forbidden')['message']);
        self::assertSame([], $response->headers->getCookies());
    }

    /**
     * @param array<string, string> $server
     */
    private static function authorize(KernelBrowser $client, array $server = []): Cookie
    {
        $response = self::apiRequest($client, 'POST', self::PATH, server: $server);
        self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/MercureAuthorization');
        $cookies = array_values(array_filter($response->headers->getCookies(), static fn (Cookie $cookie): bool => 'mercureAuthorization' === $cookie->getName()));
        self::assertCount(1, $cookies);

        return $cookies[0];
    }

    private static function parse(string $jwt): UnencryptedToken
    {
        self::assertNotSame('', $jwt);
        $token = new Parser(new JoseEncoder())->parse($jwt);
        self::assertInstanceOf(UnencryptedToken::class, $token);

        return $token;
    }
}
