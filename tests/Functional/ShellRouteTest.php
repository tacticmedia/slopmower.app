<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\ShellController;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShellRouteTest extends WebTestCase
{
    use JsonApiAssertions;
    use ResetDatabase;

    private const string ID = '01J9ZQ4R5T6V7W8X9Y0Z1A2B3C';

    #[TestDox('A signed-in user gets the shell page')]
    #[DataProvider('shellPaths')]
    public function testSignedInUserGetsTheShell(string $path): void
    {
        $client = $this->signedInClient();

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('body > div#root:empty'));
        self::assertCount(1, $crawler->filter('script[type="module"][src^="/build-e2e/"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellPaths(): iterable
    {
        foreach (['/', '/repositories', '/repositories/'.self::ID, '/reviews/'.self::ID, '/reviews/'.self::ID.'/files/src/Billing/Invoice.php', '/reviews/'.self::ID.'/tree/src/Billing', '/reviews/'.self::ID.'/requests', '/requests/'.self::ID, '/settings'] as $path) {
            yield $path => [$path];
        }
    }

    #[TestDox('An anonymous user is redirected to /login')]
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/reviews/'.self::ID);

        self::assertResponseRedirects('/login');
    }

    #[TestDox('/api/v1/x stays a JSON 404')]
    public function testUnknownApiPathStaysJsonNotFound(): void
    {
        $client = $this->signedInClient();

        $client->request('GET', '/api/v1/x');

        self::assertApiError($client->getResponse(), 404, 'not_found');
    }

    #[TestDox('/docs, /_mcp, and a path outside the shell routes are not captured')]
    #[DataProvider('foreignPaths')]
    public function testForeignPathIsNotCaptured(string $path): void
    {
        $client = $this->signedInClient();

        $client->request('GET', $path);

        self::assertNotSame(ShellController::class, $client->getRequest()->attributes->get('_controller'));
        self::assertStringNotContainsString('<div id="root">', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignPaths(): iterable
    {
        foreach (['/docs', '/_mcp', '/reviews/'.self::ID.'/files', '/reviews/'.self::ID.'/tree', '/settings/tokens'] as $path) {
            yield $path => [$path];
        }
    }

    private function signedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(TenantFixtures::of(self::getContainer())->create(), 'web');

        return $client;
    }
}
