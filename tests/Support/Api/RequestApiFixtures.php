<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Entity\User;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Requests through the API, for WebTestCase tests that use ReviewApiFixtures and its traits.
 */
trait RequestApiFixtures
{
    private const string INVOICE_TOTALS = 'src/Billing/InvoiceTotals.php';

    private const string AGENT_NAME = 'claude-code laptop';

    public const string WONT_DO_REASON = 'The totals already use integer cents.';

    /**
     * Sixty lines `    $total += <n>;`. Line 10 has trailing spaces, line 11 ends with CRLF.
     *
     * @return array<string, string> Content by path
     */
    private static function codeFiles(): array
    {
        $lines = array_map(static fn (int $line): string => "    \$total += {$line};", range(1, 60));
        $lines[9] .= '   ';
        $lines[10] .= "\r";

        return [
            self::INVOICE_TOTALS => implode("\n", $lines)."\n",
            'src/Billing/TaxRounding.php' => "<?php\n\nfinal class TaxRounding\n{\n}\n",
            'assets/logo.png' => "\x89PNG\r\n\x1a\n\0\0\0\rIHDR",
        ];
    }

    /**
     * Connects the local source "billing", which the test committed, and starts a review of main.
     *
     * @return string The review ID
     */
    private static function startCodeReview(KernelBrowser $client): string
    {
        return (string) self::startReviewThroughApi($client, self::connectAndClone($client))['id'];
    }

    /**
     * @param array<string, mixed>|null $anchor Null for a general request
     *
     * @return array<string, mixed> The Request
     */
    private static function openRequest(KernelBrowser $client, string $reviewId, ?array $anchor = ['kind' => 'file', 'path' => 'README.md'], string $body = 'Use `Money` instead of `float` for totals.'): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/requests", ['anchor' => $anchor, 'body' => $body]), 201, 'request.schema.json');
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> The RequestCommandResult
     */
    private static function requestCommand(KernelBrowser $client, string $requestId, string $command, ?array $body = null): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/requests/{$requestId}/{$command}", $body), 'comments' === $command ? 201 : 200, 'request-command-result.schema.json');
    }

    /**
     * @return array<string, mixed> The Request
     */
    private static function readRequest(KernelBrowser $client, string $requestId): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/requests/{$requestId}"), 200, 'request.schema.json');
    }

    /**
     * Runs agent commands through the application, under the tenant of the token owner.
     *
     * @param \Closure(RequestCommands, Actor): mixed $commands
     */
    private static function asAgent(User $owner, \Closure $commands, string $agentName = self::AGENT_NAME): void
    {
        $container = self::getContainer();
        $container->get(TenantContext::class)->set($owner->getTenantId());
        $commands($container->get(RequestCommands::class), Actor::agent($owner->getId(), $agentName));
        $container->get(EntityManagerInterface::class)->clear();
    }
}
