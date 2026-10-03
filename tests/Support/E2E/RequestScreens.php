<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use App\Application\ApiTokenIssuer;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use Doctrine\ORM\EntityManagerInterface;
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

    private function openRequest(string $requestId): void
    {
        $this->visit("/requests/{$requestId}");
        $this->expect($this->page->locator(self::DETAIL."[data-request-id=\"{$requestId}\"] [data-testid=\"thread-comment\"]")->first())->toBeVisible();
    }

    private function waitForStatus(string $status): void
    {
        $this->expect($this->page->locator(self::DETAIL_STATUS))->toHaveExactText($status);
    }

    private function clickRequestButton(string $label): void
    {
        $this->page->locator('[data-testid="request-actions"]')->getByRole('button', ['name' => $label, 'exact' => true])->click();
    }
}
