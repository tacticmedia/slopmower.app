<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Identity\TenantId;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CursorTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    private const string PAGE_SCHEMA = 'lists.schema.json#/$defs/RepositoryPage';

    private KernelBrowser $client;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tenantId = self::signIn($this->client)->getTenantId();

        foreach (['delta', 'Alpha', 'charlie', 'Bravo', 'echo'] as $directory) {
            self::storeRepository($this->tenantId, $directory);
        }
    }

    #[TestDox('nextCursor returns the next page and is null on the last page')]
    public function testCursorWalksThePages(): void
    {
        // Sorts between "charlie" and "delta", so it ends page 2 and its cursor carries the long name.
        $longName = 'c'.str_repeat("\u{6F22}", 110);
        self::storeRepository($this->tenantId, $longName);
        $names = [];
        $cursorLengths = [];
        $uri = '/api/v1/repositories?limit=2&source=local';

        do {
            $page = self::assertApiResponse(self::apiRequest($this->client, 'GET', $uri), 200, self::PAGE_SCHEMA);
            $names = [...$names, ...array_column($page['items'], 'name')];
            $cursorLengths[] = strlen((string) $page['nextCursor']);
            $uri = '/api/v1/repositories?limit=2&source=local&cursor='.$page['nextCursor'];
        } while (null !== $page['nextCursor']);

        self::assertSame(['Alpha', 'Bravo', 'charlie', $longName, 'delta', 'echo'], $names);
        self::assertCount(3, $cursorLengths);
        self::assertGreaterThan(512, $cursorLengths[1], 'The cursor of a long non-ASCII name fits the schema.');
    }

    #[TestDox('A cursor used with other filters gives 422')]
    public function testCursorOfOtherFiltersIsRefused(): void
    {
        $page = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories?limit=2&source=local'), 200, self::PAGE_SCHEMA);
        self::assertIsString($page['nextCursor']);

        $withoutSource = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories?limit=2&cursor={$page['nextCursor']}"), 422, 'invalid_input');
        $withOtherLimit = self::apiRequest($this->client, 'GET', "/api/v1/repositories?limit=3&source=local&cursor={$page['nextCursor']}");

        self::assertSame(['cursor'], array_column($withoutSource['details']['violations'] ?? [], 'field'));
        self::assertSame(['charlie', 'delta', 'echo'], array_column(self::assertApiResponse($withOtherLimit, 200, self::PAGE_SCHEMA)['items'], 'name'), 'The limit is not a filter.');
    }

    #[TestDox('A malformed cursor gives 422')]
    public function testMalformedCursorIsRefused(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/repositories?cursor=bm90LWpzb24'), 422, 'invalid_input');

        self::assertSame(['cursor'], array_column($error['details']['violations'] ?? [], 'field'));
    }
}
