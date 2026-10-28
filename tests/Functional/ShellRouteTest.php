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
        foreach (['/', '/repositories', '/repositories/'.self::ID, '/reviews/'.self::ID, '/reviews/'.self::ID.'/files/src/Billing/Invoice.php', '/reviews/'.self::ID.'/tree/src/Billing', '/reviews/'.self::ID.'/blame/src/Billing/Invoice.php', '/reviews/'.self::ID.'/requests', '/requests/'.self::ID, '/settings'] as $path) {
            yield $path => [$path];
        }
    }

    /**
     * @param array<string, string>      $environment
     * @param array<string, string|null> $attributes
     */
    #[TestDox('The shell body carries the release and the browser Sentry settings only when they are set')]
    #[DataProvider('bodyAttributes')]
    public function testShellBodyAttributes(array $environment, array $attributes): void
    {
        $previous = array_intersect_key($_ENV, $environment);
        $_ENV = [...$_ENV, ...$environment];

        try {
            $body = $this->signedInClient()->request('GET', '/repositories')->filter('body');
        } finally {
            $_ENV = [...array_diff_key($_ENV, $environment), ...$previous];
        }

        foreach ($attributes as $name => $value) {
            self::assertSame($value, $body->attr($name), $name);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string|null>}>
     */
    public static function bodyAttributes(): iterable
    {
        $dsn = 'https://public@sentry.example.com/1';

        yield 'nothing set' => [[], ['data-release' => null, 'data-sentry-dsn' => null, 'data-sentry-environment' => null]];
        yield 'the kernel environment' => [['APP_RELEASE' => 'abc123', 'SENTRY_DSN_FRONTEND' => $dsn], ['data-release' => 'abc123', 'data-sentry-dsn' => $dsn, 'data-sentry-environment' => 'test']];
        yield 'an own environment' => [['SENTRY_DSN_FRONTEND' => $dsn, 'SENTRY_ENVIRONMENT' => 'staging'], ['data-release' => null, 'data-sentry-dsn' => $dsn, 'data-sentry-environment' => 'staging']];
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
