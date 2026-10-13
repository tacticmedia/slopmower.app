<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GitHubInstalledRouteTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('/github/installed redirects through /connect/github with _target_path /repositories')]
    public function testSetupUrlSignsInAgainAndShowsTheRepositories(): void
    {
        $client = static::createClient();
        $client->loginUser(TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create(), 'web');

        $client->request('GET', '/github/installed', ['installation_id' => '5551', 'setup_action' => 'update']);

        self::assertResponseRedirects('/connect/github?_target_path=/repositories');
        $client->followRedirect();
        self::signInAtGitHub($client);
        self::assertResponseRedirects('http://localhost/repositories');
    }

    #[TestDox('A signed-out request to /github/installed, as after a Marketplace purchase, reaches the repositories after the sign-in')]
    public function testSignedOutSetupUrlReachesTheRepositoriesAfterTheSignIn(): void
    {
        $client = static::createClient();

        $client->request('GET', '/github/installed', ['installation_id' => '5552', 'setup_action' => 'install']);

        self::assertResponseRedirects('http://localhost/login');
        $client->request('GET', '/connect/github');
        self::signInAtGitHub($client);
        self::assertResponseRedirects('http://localhost/github/installed?installation_id=5552&setup_action=install');
        $client->followRedirect();
        self::assertResponseRedirects('/connect/github?_target_path=/repositories');
        $client->followRedirect();
        self::signInAtGitHub($client);
        self::assertResponseRedirects('http://localhost/repositories');
    }

    /**
     * Answers the GitHub authorization that the last response redirects to.
     */
    private static function signInAtGitHub(KernelBrowser $client): void
    {
        $authorizeUrl = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $authorizeUrl);
        parse_str((string) parse_url($authorizeUrl, \PHP_URL_QUERY), $query);

        $client->request('GET', '/login/check-github', ['code' => 'a1b2c3d4', 'state' => $query['state'] ?? '']);
    }
}
