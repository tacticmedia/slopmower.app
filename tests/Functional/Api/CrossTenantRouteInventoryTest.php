<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\CrossTenant\CrossTenantCases;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

final class CrossTenantRouteInventoryTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('Fails when an /api/v1 route has no cross-tenant case')]
    public function testEveryRouteHasACase(): void
    {
        $routes = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/v1')) {
                $routes[$name] = ['methods' => $route->getMethods(), 'path' => $route->getPath()];
            }
        }

        $cases = CrossTenantCases::all();
        ksort($routes);
        ksort($cases);

        self::assertSame(array_keys($routes), array_keys($cases), 'Every /api/v1 route has exactly one case in CrossTenantCases.');

        foreach ($cases as $name => $case) {
            self::assertSame([$case['method']], $routes[$name]['methods'], "The method of the case {$name}.");
            self::assertSame($routes[$name]['path'], $case['path'], "The path of the case {$name}.");
        }
    }

    /**
     * @param array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null} $case
     */
    #[TestDox("Each case with the other tenant's IDs gets 404 not_found")]
    #[DataProvider('casesWithIds')]
    public function testOtherTenantIdsGetNotFound(array $case): void
    {
        [$client, $otherTenantIds] = $this->twoTenants();
        $path = (string) preg_replace_callback('/\{(\w+)}/', static fn (array $match): string => $otherTenantIds[$match[1]] ?? self::fail("No ID of the other tenant for {$match[0]}."), $case['path']);

        self::assertApiError(self::apiRequest($client, $case['method'], $path, $case['body']), 404, 'not_found');
    }

    /**
     * @param array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null} $case
     */
    #[TestDox("List routes return none of the other tenant's items")]
    #[DataProvider('casesWithoutIds')]
    public function testResponsesHoldNoOtherTenantIds(array $case): void
    {
        [$client, $otherTenantIds] = $this->twoTenants();

        $response = self::apiRequest($client, $case['method'], $case['path'], $case['body']);

        self::assertNotNull($case['schema'], 'A list or create route answers with a body.');
        self::assertApiResponse($response, $case['status'], $case['schema']);

        foreach ($otherTenantIds as $id) {
            self::assertStringNotContainsStringIgnoringCase($id, (string) $response->getContent());
        }
    }

    /**
     * @return iterable<string, array{array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null}}>
     */
    public static function casesWithIds(): iterable
    {
        foreach (CrossTenantCases::all() as $name => $case) {
            if (str_contains($case['path'], '{')) {
                yield $name => [$case];
            }
        }
    }

    /**
     * @return iterable<string, array{array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null}}>
     */
    public static function casesWithoutIds(): iterable
    {
        foreach (CrossTenantCases::all() as $name => $case) {
            if (!str_contains($case['path'], '{')) {
                yield $name => [$case];
            }
        }
    }

    /**
     * The other tenant connected the local source "billing" and has an API token. The signed-in tenant has its own
     * repository "alpha".
     *
     * @return array{KernelBrowser, array<string, string>} The client, and the IDs of the other tenant by placeholder name
     */
    private function twoTenants(): array
    {
        $this->useTemporaryLocalSources();
        $this->newSource('billing');
        $client = static::createClient();
        $other = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        $otherRepository = self::storeRepository($other->getTenantId(), 'billing');
        [$otherToken] = self::storeToken($other);
        self::storeRepository(self::signIn($client)->getTenantId(), 'alpha');

        return [$client, [
            'tenantId' => $other->getTenantId()->toString(),
            'userId' => $other->getId()->toString(),
            'repositoryId' => $otherRepository->getId()->toString(),
            'tokenId' => $otherToken->getId()->toString(),
        ]];
    }
}
