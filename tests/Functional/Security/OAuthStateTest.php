<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\GitHub\GitHubResponses;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\DataCollector\HttpClientDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class OAuthStateTest extends WebTestCase
{
    use ResetDatabase;

    /**
     * @param array<string, string> $stateParameter
     */
    #[TestDox('A callback with a missing or wrong state is rejected')]
    #[DataProvider('invalidStates')]
    public function testCallbackWithInvalidStateIsRejected(array $stateParameter): void
    {
        $client = static::createClient();
        self::connect($client);
        $client->enableProfiler();

        $client->request('GET', '/login/check-github', ['code' => 'a1b2c3d4'] + $stateParameter);

        self::assertResponseRedirects('http://localhost/login');
        self::assertSame([], self::gitHubRequests($client), 'No code exchange without a valid state.');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM app_user'));
        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidStates(): iterable
    {
        yield 'missing' => [[]];
        yield 'wrong' => [['state' => base64_encode('{"csrf_token":"not-the-stored-token"}')]];
    }

    #[TestDox('A callback with the state from /connect/github signs the user in, and the session keeps no GitHub token')]
    public function testCallbackWithValidStateSignsIn(): void
    {
        $client = static::createClient();
        $state = self::connect($client);
        $client->enableProfiler();

        $client->request('GET', '/login/check-github', ['code' => 'a1b2c3d4', 'state' => $state]);

        self::assertResponseRedirects('http://localhost/');
        self::assertSame(
            [
                'POST https://github.com/login/oauth/access_token',
                'GET https://api.github.com/user',
                'GET https://api.github.com/user/emails',
            ],
            self::gitHubRequests($client),
        );
        $session = self::sessionFileContent($client);
        self::assertStringContainsString('_security_web', $session);
        self::assertStringNotContainsString(GitHubResponses::ACCESS_TOKEN, $session, 'The user access token is not stored.');
        self::assertStringNotContainsString('ghr_', $session, 'The refresh token is not stored.');

        $client->request('GET', '/api/v1/me');
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertSame(['login' => 'octocat', 'signInMethod' => 'github'], array_intersect_key((array) $me['user'], ['login' => 0, 'signInMethod' => 0]));
    }

    #[TestDox('A kernel that serves many requests, as a FrankenPHP worker does, gives each sign-in its own state')]
    public function testLongRunningKernelGivesEachSignInItsOwnState(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $first = self::connect($client);
        $client->getCookieJar()->clear();
        $second = self::connect($client);

        self::assertNotSame($first, $second);
    }

    /**
     * @return string the state that GitHub sends back to the callback
     */
    private static function connect(KernelBrowser $client): string
    {
        $client->request('GET', '/connect/github');
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }

    /**
     * @return list<string> "<method> <url>" of the last request, in call order
     */
    private static function gitHubRequests(KernelBrowser $client): array
    {
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('http_client');
        self::assertInstanceOf(HttpClientDataCollector::class, $collector);

        return array_values(array_map(
            static fn (array $trace): string => "{$trace['method']} {$trace['url']}",
            $collector->getClients()['github.client']['traces'] ?? [],
        ));
    }

    private static function sessionFileContent(KernelBrowser $client): string
    {
        $sessionId = $client->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotNull($sessionId);
        $cacheDirectory = self::getContainer()->getParameter('kernel.cache_dir');

        return (string) file_get_contents($cacheDirectory.'/sessions/'.$sessionId.'.mocksess');
    }
}
