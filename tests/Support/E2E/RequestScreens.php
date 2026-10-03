<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use App\Application\ApiTokenIssuer;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client;
use Symfony\Component\Uid\Ulid;

/**
 * The request screens in the browser, and the agent's side over MCP, for tests that use {@see CodeComments}.
 */
trait RequestScreens
{
    use CodeComments;
    use McpAssertions;

    private const string DETAIL = '[data-testid="request-detail"]';
    private const string DETAIL_STATUS = self::DETAIL.' header [data-testid="status-label"]';

    /**
     * An agent of the commenter with a token issued by T2's service.
     */
    private function agent(string $name = 'claude-code laptop'): McpTestClient
    {
        $container = self::getContainer();
        $container->get(TenantContext::class)->set($this->commenter->getTenantId());
        $owner = $container->get(EntityManagerInterface::class)->find(User::class, Ulid::fromString($this->commenter->getId()->toString()));
        self::assertInstanceOf(User::class, $owner);

        return McpHttpClient::connect($container->get(ApiTokenIssuer::class)->issue($owner, $name, null, null)->secret->value);
    }

    /**
     * Calls the tool and checks its structuredContent against docs/specs.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The structuredContent
     */
    private static function agentCalls(McpTestClient $agent, string $tool, array $arguments): array
    {
        return self::assertToolResult($agent, $agent->callTool($tool, $arguments), $tool);
    }

    private static function openRequest(Client $client, string $requestId): void
    {
        $client->request('GET', "/requests/{$requestId}");
        $client->waitFor(self::DETAIL."[data-request-id=\"{$requestId}\"] [data-testid=\"thread-comment\"]");
    }

    private static function waitForStatus(Client $client, string $status): void
    {
        $client->waitForElementToContain(self::DETAIL_STATUS, $status);
        self::assertSelectorTextSame(self::DETAIL_STATUS, $status);
    }

    private static function clickRequestButton(Client $client, string $label): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::xpath("//*[@data-testid=\"request-actions\"]//button[normalize-space()=\"{$label}\"]"))->click();
    }
}
