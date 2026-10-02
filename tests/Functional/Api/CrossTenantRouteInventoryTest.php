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
use Symfony\Component\HttpFoundation\Response;
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
     * @param array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null, bearer?: true} $case
     */
    #[TestDox("Each case with the other tenant's IDs gets 404 not_found")]
    #[DataProvider('casesWithIds')]
    public function testOtherTenantIdsGetNotFound(array $case): void
    {
        [$client, $otherTenantIds, $secret] = $this->twoTenants();
        $withId = static fn (array $match): string => $otherTenantIds[$match[1]] ?? self::fail("No ID of the other tenant for {$match[0]}.");
        $path = (string) preg_replace_callback('/\{(\w+)}/', $withId, $case['path']);
        $body = $case['body'];

        if (null !== $body) {
            array_walk_recursive($body, static function (mixed &$value) use ($withId): void {
                $value = \is_string($value) ? preg_replace_callback('/^\{(\w+)}$/', $withId, $value) : $value;
            });
        }

        self::assertApiError(self::caseRequest($client, $secret, $case, $path, $body), 404, 'not_found');
    }

    /**
     * @param array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null, bearer?: true} $case
     */
    #[TestDox("List routes return none of the other tenant's items")]
    #[DataProvider('casesWithoutIds')]
    public function testResponsesHoldNoOtherTenantIds(array $case): void
    {
        [$client, $otherTenantIds, $secret] = $this->twoTenants();

        $response = self::caseRequest($client, $secret, $case, $case['path'], $case['body']);

        self::assertNotNull($case['schema'], 'A list or create route answers with a body.');
        self::assertApiResponse($response, $case['status'], $case['schema']);

        foreach ($otherTenantIds as $id) {
            self::assertStringNotContainsStringIgnoringCase($id, (string) $response->getContent());
        }
    }

    /**
     * @return iterable<string, array{array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null, bearer?: true}}>
     */
    public static function casesWithIds(): iterable
    {
        foreach (CrossTenantCases::all() as $name => $case) {
            if (self::hasPlaceholder($case)) {
                yield $name => [$case];
            }
        }
    }

    /**
     * @return iterable<string, array{array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null, bearer?: true}}>
     */
    public static function casesWithoutIds(): iterable
    {
        foreach (CrossTenantCases::all() as $name => $case) {
            if (!self::hasPlaceholder($case)) {
                yield $name => [$case];
            }
        }
    }

    /**
     * @param array{path: string, body: array<string, mixed>|null, ...} $case
     */
    private static function hasPlaceholder(array $case): bool
    {
        return str_contains($case['path'], '{') || 1 === preg_match('/"\{\w+}"/', json_encode($case['body'], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{method: string, bearer?: true, ...} $case
     * @param array<string, mixed>|null                 $body
     */
    private static function caseRequest(KernelBrowser $client, #[\SensitiveParameter] string $secret, array $case, string $path, ?array $body): Response
    {
        return ($case['bearer'] ?? false) ? self::bearerRequest($client, $secret, $case['method'], $path, $body) : self::apiRequest($client, $case['method'], $path, $body);
    }

    /**
     * The other tenant connected the local source "billing", has a review of it with a request, and has an API token.
     * The signed-in tenant has its own repository "alpha" and an API token.
     *
     * @return array{KernelBrowser, array<string, string>, string} The client, the IDs of the other tenant by placeholder
     *                                                             name, and the secret of the signed-in tenant's token
     */
    private function twoTenants(): array
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource('billing');
        $client = static::createClient();
        $other = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        $otherReview = self::storeReview($other, 'billing');
        $otherRequest = self::storeRequest($otherReview);
        [$otherToken] = self::storeToken($other);
        $user = self::signIn($client);
        self::storeRepository($user->getTenantId(), 'alpha');
        [, $secret] = self::storeToken($user);

        return [$client, [
            'tenantId' => $other->getTenantId()->toString(),
            'userId' => $other->getId()->toString(),
            'repositoryId' => $otherReview->getRepository()->getId()->toString(),
            'reviewId' => $otherReview->getId()->toString(),
            'requestId' => $otherRequest->getId()->toString(),
            'draftId' => self::storeDraft($other, $otherRequest)->getId()->toString(),
            'blobSha' => $source->revParse('main:README.md'),
            'tokenId' => $otherToken->getId()->toString(),
            'commitSha' => $otherReview->getCommitSha()->toString(),
        ], $secret];
    }
}
