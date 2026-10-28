<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginRoutesTest extends WebTestCase
{
    use EnvironmentVariables;
    use ResetDatabase;

    private const string CLIENT_ID = 'GITHUB_APP_CLIENT_ID';
    private const string GITHUB_BUTTON = 'a[href="/connect/github"]';

    #[TestDox('/connect/github redirects to GitHub with the client ID and a state')]
    public function testConnectRedirectsToGitHub(): void
    {
        $client = static::createClient();

        $client->request('GET', '/connect/github');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('Iv23liFakeTestClient', $query['client_id'] ?? null);
        self::assertSame('http://localhost/login/check-github', $query['redirect_uri'] ?? null);
        self::assertIsString($query['state'] ?? null);
        self::assertNotSame('', $query['state']);
    }

    #[TestDox('/login shows the GitHub button only when the App is configured')]
    #[DataProvider('clientIds')]
    public function testLoginShowsGitHubButtonOnlyWhenConfigured(string $clientId, int $expectedButtons): void
    {
        $this->setEnvironmentVariable(self::CLIENT_ID, $clientId);
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount($expectedButtons, $crawler->filter(self::GITHUB_BUTTON));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function clientIds(): iterable
    {
        yield 'configured' => ['Iv23liFakeTestClient', 1];
        yield 'not configured' => ['', 0];
    }

    #[TestDox('/login links the privacy policy, the terms of service, and the support page')]
    public function testLoginLinksTheLegalAndSupportPages(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertSame(['/docs/privacy', '/docs/terms', '/docs/support'], $crawler->filter('footer a')->extract(['href']));
    }

    #[TestDox('/login links the favicon, the SVG icon, and the Apple touch icon, and each file is in public/')]
    public function testLoginLinksIconsThatExist(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        $hrefs = $crawler->filter('link[rel="icon"], link[rel="apple-touch-icon"]')->extract(['href']);
        self::assertSame(['/favicon.ico', '/favicon.svg', '/apple-touch-icon.png'], $hrefs);
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        foreach ($hrefs as $href) {
            self::assertFileExists($projectDirectory.'/public'.$href);
        }
    }

    #[TestDox('Without a client ID, /connect/github answers 404')]
    public function testWithoutClientIdTheRoutesAnswer404(): void
    {
        $this->setEnvironmentVariable(self::CLIENT_ID, '');
        $client = static::createClient();

        $client->request('GET', '/connect/github');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/login/check-github', ['code' => 'a1b2c3d4', 'state' => 'any']);
        self::assertResponseStatusCodeSame(404);
    }
}
