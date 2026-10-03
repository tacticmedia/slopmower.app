<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Docs\DocsCatalog;
use App\Domain\Identity\Identifier;
use App\Domain\Identity\TenantId;
use App\Git\TenantDataPaths;
use App\Live\LiveTopics;
use App\Tests\Functional\Mcp\McpCrossTenantInventoryTest;
use App\Tests\Functional\ShellRouteTest;
use App\Tests\Functional\Webhook\WebhookTenantFilterTest;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\CrossTenant\CrossTenantCases;
use App\Tests\Support\CrossTenant\McpCrossTenantCases;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\RouterInterface;

/**
 * Inventories every tenant surface. {@see \App\Tests\Functional\Api\CrossTenantRouteInventoryTest} and
 * {@see McpCrossTenantInventoryTest} run the route and tool cases; this class runs a generated case for each topic and
 * path builder.
 */
final class CrossTenantCoverageTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;

    private const string PROJECT_DIRECTORY = __DIR__.'/../../..';

    /**
     * The routes of the test environment outside /api/v1, by surface: `mcp` {@see McpCrossTenantInventoryTest},
     * `webhook` {@see WebhookTenantFilterTest}, `docs` the test of this class, `shell` the empty page of
     * {@see ShellRouteTest}, `sign-in` sign-in, sign-out, and health, without tenant data.
     */
    private const array ROUTES_OUTSIDE_API = [
        '_mcp_endpoint_holistic_review' => 'mcp',
        '_webhook_controller' => 'webhook',
        'docs_index' => 'docs',
        'docs_page' => 'docs',
        'docs_schema' => 'docs',
        'docs_skill' => 'docs',
        'shell_home' => 'shell',
        'shell_repositories' => 'shell',
        'shell_repository' => 'shell',
        'shell_request' => 'shell',
        'shell_review' => 'shell',
        'shell_review_file' => 'shell',
        'shell_review_requests' => 'shell',
        'shell_settings' => 'shell',
        'app_login' => 'sign-in',
        'app_logout' => 'sign-in',
        'github_installed' => 'sign-in',
        'github_login_check' => 'sign-in',
        'health' => 'sign-in',
        'hwi_oauth_service_redirect' => 'sign-in',
    ];

    /** An RFC 6570 simple expansion, as the hub matches a URI template: never a slash. */
    private const string SIMPLE_EXPANSION = '(?:[A-Za-z0-9\-._~]|%[0-9A-Fa-f]{2})+';

    #[TestDox('Every /api/v1 route, MCP tool, Mercure topic builder, and tenant file path has a cross-tenant case')]
    public function testEverySurfaceHasACrossTenantCase(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());

        self::assertEveryRouteHasACase();
        self::assertEveryToolHasACase(new McpTestClient($client, $secret));
        self::assertTopicBuildersSeparateTenants();
        self::assertTenantPathsSeparateTenants();
    }

    #[TestDox('The public documentation routes return no tenant data')]
    public function testDocumentationRoutesReturnNoTenantData(): void
    {
        $client = static::createClient();
        $user = self::signIn($client, 'zq-marker-login');
        $review = self::storeReview($user, 'zq-marker-repository');
        [$token] = self::storeToken($user);
        $tenantData = [
            $user->getTenantId()->toString(),
            $user->getId()->toString(),
            'zq-marker',
            $review->getRepository()->getId()->toString(),
            $review->getId()->toString(),
            self::storeRequest($review)->getId()->toString(),
            $token->getId()->toString(),
        ];
        $signedInBodies = [];

        foreach (self::documentationPaths() as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSame([], $client->getResponse()->headers->getCookies(), "{$path} sets no cookie.");
            $signedInBodies[$path] = $client->getInternalResponse()->getContent();

            foreach ($tenantData as $value) {
                self::assertStringNotContainsStringIgnoringCase($value, $signedInBodies[$path], $path);
            }
        }

        $client->getCookieJar()->clear();

        foreach ($signedInBodies as $path => $body) {
            $client->request('GET', $path);
            self::assertSame($body, $client->getInternalResponse()->getContent(), "{$path} answers a signed-in user as an anonymous one.");
        }
    }

    private static function assertEveryRouteHasACase(): void
    {
        $apiRoutes = [];
        $otherRoutes = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/v1')) {
                $apiRoutes[$name] = ['methods' => $route->getMethods(), 'path' => $route->getPath()];
            } else {
                $otherRoutes[] = $name;
            }
        }

        $cases = CrossTenantCases::all();
        ksort($apiRoutes);
        ksort($cases);
        sort($otherRoutes);
        $classifiedRoutes = array_keys(self::ROUTES_OUTSIDE_API);
        sort($classifiedRoutes);

        self::assertSame(array_keys($apiRoutes), array_keys($cases), 'Every /api/v1 route has exactly one case in CrossTenantCases.');
        self::assertSame($classifiedRoutes, $otherRoutes, 'Every route outside /api/v1 has its surface in ROUTES_OUTSIDE_API.');

        foreach ($cases as $name => $case) {
            self::assertSame([$case['method']], $apiRoutes[$name]['methods'], "The method of the case {$name}.");
            self::assertSame($apiRoutes[$name]['path'], $case['path'], "The path of the case {$name}.");
        }
    }

    private static function assertEveryToolHasACase(McpTestClient $mcp): void
    {
        $tools = array_column($mcp->tools(), 'name');
        $cases = array_keys(McpCrossTenantCases::all());
        sort($tools);
        sort($cases);

        self::assertSame($tools, $cases, 'Every MCP tool has exactly one entry in McpCrossTenantCases.');
    }

    /**
     * No topic or selector of one tenant matches one of another tenant, in either direction. Topics reach the hub only
     * through LiveTopics.
     */
    private static function assertTopicBuildersSeparateTenants(): void
    {
        $topics = self::getContainer()->get(LiveTopics::class);
        $ofA = self::builderOutputs($topics, TenantId::generate());
        $ofB = self::builderOutputs($topics, TenantId::generate());

        foreach ($ofA as $a) {
            foreach ($ofB as $b) {
                self::assertFalse(self::selectorMatches($a, $b), "{$a} matches {$b}.");
                self::assertFalse(self::selectorMatches($b, $a), "{$b} matches {$a}.");
            }
        }

        self::assertSame([], self::publishCallsWithoutLiveTopics(), 'Every publish() call takes its topic from LiveTopics, and only LivePublisher builds an Update.');
    }

    /**
     * No path of one tenant lies in a path of another tenant. Only TenantDataPaths reads the tenant data root.
     */
    private static function assertTenantPathsSeparateTenants(): void
    {
        $paths = self::getContainer()->get(TenantDataPaths::class);
        $ofA = self::builderOutputs($paths, TenantId::generate());
        $ofB = self::builderOutputs($paths, TenantId::generate());

        foreach ($ofA as $a) {
            foreach ($ofB as $b) {
                self::assertFalse(Path::isBasePath($b, $a), "{$a} lies in {$b}.");
                self::assertFalse(Path::isBasePath($a, $b), "{$b} lies in {$a}.");
            }
        }

        $readers = array_keys(array_filter(self::sources(['src', 'config']), static fn (string $source): bool => str_contains($source, 'APP_TENANT_DATA_DIR')));
        self::assertSame(['src/Git/TenantDataPaths.php'], $readers);
    }

    /**
     * Calls every public method of the builder for the tenant. A method's other parameters are new typed IDs.
     *
     * @return list<string>
     */
    private static function builderOutputs(object $builder, TenantId $tenantId): array
    {
        $outputs = [];

        foreach (new \ReflectionObject($builder)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            $arguments = [];

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $class = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                self::assertTrue(is_a($class, Identifier::class, true), "Give {$method->class}::{$method->name}() a case for the parameter \${$parameter->name}.");
                $arguments[] = TenantId::class === $class ? $tenantId : $class::generate();
            }

            self::assertContains($tenantId, $arguments, "{$method->class}::{$method->name}() takes a tenant.");
            foreach ((array) $method->invokeArgs($builder, $arguments) as $output) {
                self::assertIsString($output);
                self::assertContains($tenantId->toString(), explode('/', $output), "{$output} has the tenant ID as a segment.");
                $outputs[] = $output;
            }
        }

        self::assertNotSame([], $outputs);

        return $outputs;
    }

    /**
     * Equality, or a URI template whose variables are simple expansions.
     */
    private static function selectorMatches(string $selector, string $topic): bool
    {
        $pattern = preg_replace('/\\\\\{\w+\\\\}/', self::SIMPLE_EXPANSION, preg_quote($selector, '#'));

        return $selector === $topic || 1 === preg_match("#^{$pattern}$#", $topic);
    }

    /**
     * Messenger routes a Mercure `Update` message to the hub, so only LivePublisher may build one.
     *
     * @return list<string> File and line of each publish() call whose first argument is not a LiveTopics call, and of
     *                      each `new Update(`
     */
    private static function publishCallsWithoutLiveTopics(): array
    {
        $violations = [];

        foreach (self::sources(['src']) as $file => $source) {
            if ('src/Live/LivePublisher.php' === $file) {
                continue;
            }

            $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $token): bool => !$token->isIgnorable()));

            foreach ($tokens as $i => $token) {
                $newUpdate = $token->is(\T_NEW) && 'Update' === basename(str_replace('\\', '/', $tokens[$i + 1]->text ?? ''));
                $publishCall = $token->is(\T_STRING) && 'publish' === $token->text
                    && $tokens[$i - 1]->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) && $tokens[$i + 1]->is('(');
                $argument = implode('', array_map(static fn (\PhpToken $part): string => $part->text, \array_slice($tokens, $i + 2, 4)));

                if ($newUpdate || ($publishCall && !str_starts_with($argument, '$this->topics->') && !str_starts_with($argument, '$topics->'))) {
                    $violations[] = "{$file}:{$token->line}";
                }
            }
        }

        return $violations;
    }

    /**
     * Every concrete path under /docs: each page, each schema file, and the skill inline and as a download.
     *
     * @return list<string>
     */
    private static function documentationPaths(): array
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $catalog = self::getContainer()->get(DocsCatalog::class);
        $schemaNames = array_map(static fn (string $file): string => basename($file, '.schema.json'), glob(self::PROJECT_DIRECTORY.'/docs/specs/schemas/*.schema.json') ?: []);
        $parameterSets = [
            'docs_index' => [[]],
            'docs_page' => array_map(static fn (string $slug): array => ['slug' => $slug], $catalog->pageSlugs()),
            'docs_schema' => array_map(static fn (string $name): array => ['name' => $name], $schemaNames),
            'docs_skill' => [[], ['download' => '1']],
        ];
        $docsRoutes = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/docs')) {
                $docsRoutes[] = $name;
            }
        }

        sort($docsRoutes);
        self::assertSame($docsRoutes, array_keys($parameterSets), 'Every docs route has its paths.');
        $paths = [];

        foreach ($parameterSets as $name => $sets) {
            self::assertNotSame([], $sets, $name);

            foreach ($sets as $parameters) {
                $paths[] = $router->generate($name, $parameters);
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $directories Relative to the project
     *
     * @return array<string, string> Content by path relative to the project
     */
    private static function sources(array $directories): array
    {
        $sources = [];
        $finder = Finder::create()->files()->in(array_map(static fn (string $directory): string => self::PROJECT_DIRECTORY.'/'.$directory, $directories));

        foreach ($finder as $file) {
            $sources[Path::makeRelative($file->getRealPath(), (string) realpath(self::PROJECT_DIRECTORY))] = $file->getContents();
        }

        return $sources;
    }
}
