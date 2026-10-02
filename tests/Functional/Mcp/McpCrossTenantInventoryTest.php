<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\CrossTenant\McpCrossTenantCases;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpCrossTenantInventoryTest extends WebTestCase
{
    use ApiFixtures;
    use McpAssertions;
    use ResetDatabase;

    #[TestDox('Fails when a tool has no cross-tenant case')]
    public function testEveryToolHasACase(): void
    {
        [$mcp] = $this->twoTenants();

        $tools = array_column(McpTestClient::result($mcp->request('tools/list'))['tools'], 'name');
        $cases = array_keys(McpCrossTenantCases::all());
        sort($tools);
        sort($cases);

        self::assertSame($tools, $cases, 'Every MCP tool has exactly one entry in McpCrossTenantCases.');
    }

    /**
     * @param array<string, string> $arguments
     */
    #[TestDox("Each tool with the other tenant's IDs gives not_found")]
    #[DataProvider('argumentSets')]
    public function testOtherTenantIdsGiveNotFound(string $tool, array $arguments): void
    {
        [$mcp, $otherTenantIds] = $this->twoTenants();
        $arguments = array_map(static fn (string $value): string => (string) preg_replace_callback(
            '/^\{(\w+)}$/',
            static fn (array $match): string => $otherTenantIds[$match[1]] ?? self::fail("No ID of the other tenant for {$match[0]}."),
            $value,
        ), $arguments);

        self::assertToolError($mcp->callTool($tool, $arguments), 'not_found');
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function argumentSets(): iterable
    {
        foreach (McpCrossTenantCases::all() as $tool => $argumentSets) {
            foreach ($argumentSets as $arguments) {
                yield $tool.' '.json_encode($arguments) => [$tool, $arguments];
            }
        }
    }

    #[TestDox("Tools without IDs return none of the other tenant's items")]
    public function testToolsWithoutIdsHoldNoOtherTenantIds(): void
    {
        [$mcp, $otherTenantIds] = $this->twoTenants();

        foreach (array_keys(array_filter(McpCrossTenantCases::all(), static fn (array $argumentSets): bool => [] === $argumentSets)) as $tool) {
            $result = $mcp->callTool($tool);
            self::assertToolResult($mcp, $result, $tool);

            foreach ($otherTenantIds as $id) {
                self::assertStringNotContainsStringIgnoringCase($id, (string) json_encode($result), $tool);
            }
        }
    }

    #[TestDox('A scoped token sees one repository')]
    public function testScopedTokenSeesOneRepository(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        $alpha = self::storeRepository($user->getTenantId(), 'alpha');
        self::storeRepository($user->getTenantId(), 'beta');
        [, $secret] = self::storeToken($user, $alpha);
        $mcp = new McpTestClient($client, $secret);

        $page = self::assertToolResult($mcp, $mcp->callTool('list_repositories'), 'list_repositories');

        self::assertIsArray($page['items']);
        self::assertSame([$alpha->getId()->toString()], array_column($page['items'], 'id'));
        self::assertToolError($mcp->callTool('list_reviews', ['repository' => 'beta']), 'not_found');
    }

    #[TestDox('After a scoped call, the next call in the same worker has neither its tenant nor its scope')]
    public function testWorkerResetsTenantAndScopeBetweenCalls(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scopedOwner = TenantFixtures::of(self::getContainer())->create();
        $alpha = self::storeRepository($scopedOwner->getTenantId(), 'alpha');
        self::storeRepository($scopedOwner->getTenantId(), 'beta');
        $nextOwner = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        self::storeRepository($nextOwner->getTenantId(), 'gamma');
        self::storeRepository($nextOwner->getTenantId(), 'delta');
        $scoped = new McpTestClient($client, self::storeToken($scopedOwner, $alpha)[1]);
        $next = new McpTestClient($client, self::storeToken($nextOwner)[1]);

        $scopedPage = self::assertToolResult($scoped, $scoped->callTool('list_repositories'), 'list_repositories');
        $nextPage = self::assertToolResult($next, $next->callTool('list_repositories'), 'list_repositories');

        self::assertIsArray($scopedPage['items']);
        self::assertIsArray($nextPage['items']);
        self::assertSame(['alpha'], array_column($scopedPage['items'], 'name'));
        self::assertSame(['delta', 'gamma'], array_column($nextPage['items'], 'name'));
    }

    /**
     * The other tenant connected the local source "billing" and has a review of it with a request. The token's tenant
     * has its own repository "alpha".
     *
     * @return array{McpTestClient, array<string, string>} The client, and the IDs of the other tenant by placeholder name
     */
    private function twoTenants(): array
    {
        $client = static::createClient();
        $other = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        $otherReview = self::storeReview($other, 'billing');
        $user = TenantFixtures::of(self::getContainer())->create();
        self::storeRepository($user->getTenantId(), 'alpha');
        [, $secret] = self::storeToken($user);

        return [new McpTestClient($client, $secret), [
            'tenantId' => $other->getTenantId()->toString(),
            'userId' => $other->getId()->toString(),
            'repositoryId' => $otherReview->getRepository()->getId()->toString(),
            'reviewId' => $otherReview->getId()->toString(),
            'requestId' => self::storeRequest($otherReview)->getId()->toString(),
        ]];
    }
}
