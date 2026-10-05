<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Application\Event\RepositoryFetched;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Agent writes through REST, and what they leave behind, for WebTestCase tests that use ApiFixtures and
 * JsonApiAssertions.
 */
trait AgentApiFixtures
{
    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> The response body
     */
    private static function agentRequest(
        KernelBrowser $client,
        #[\SensitiveParameter] string $secret,
        string $method,
        string $uri,
        ?array $body = null,
        int $status = 200,
        string $schema = 'request-command-result.schema.json',
    ): array {
        return self::assertApiResponse(self::bearerRequest($client, $secret, $method, $uri, $body), $status, $schema);
    }

    /**
     * Counts the successful fetches from now on. Disables the reboot of the client, so its next requests use the
     * container that has the listener.
     *
     * @return \Closure(): int
     */
    private static function countFetches(KernelBrowser $client): \Closure
    {
        $client->disableReboot();
        $count = 0;
        self::getContainer()->get('event_dispatcher')->addListener(RepositoryFetched::class, static function () use (&$count): void {
            ++$count;
        });

        return static function () use (&$count): int {
            return $count;
        };
    }
}
