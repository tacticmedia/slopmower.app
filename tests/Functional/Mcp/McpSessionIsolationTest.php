<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Clock\SleepHookClock;
use App\Tests\Support\Database\LockStores;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A session belongs to the token that opened it and serves one request at a time. A lock held in its own database
 * session stands for a running request of the session in another worker.
 */
final class McpSessionIsolationTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use ResetDatabase;

    #[TestDox("A session ID of another token gets 404, and that token's DELETE keeps the session")]
    public function testSessionIdOfAnotherTokenIsNotFound(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        [, $secret] = self::storeToken($user, name: 'claude-code laptop');
        [, $otherSecret] = self::storeToken($user, name: 'codex desktop');
        $mcp = new McpTestClient($client, $secret);
        $mcp->initialize();

        $ping = self::sessionRequest($client, $otherSecret, (string) $mcp->sessionId(), 'POST');
        $delete = self::sessionRequest($client, $otherSecret, (string) $mcp->sessionId(), 'DELETE');

        self::assertSame(Response::HTTP_NOT_FOUND, $ping->getStatusCode(), (string) $ping->getContent());
        self::assertStringContainsString('Session not found', (string) $ping->getContent());
        self::assertSame(Response::HTTP_OK, $delete->getStatusCode());
        self::assertSame([], McpTestClient::result($mcp->request('ping')));
    }

    #[TestDox('A call waits while another request of its session runs')]
    public function testCallWaitsForTheRunningRequestOfItsSession(): void
    {
        [$mcp] = $this->initializedSession();
        $runningRequest = self::lockInAnotherSession((string) $mcp->sessionId());
        $clock = new SleepHookClock(static function () use ($runningRequest): void {
            $runningRequest->release();
        });
        Clock::set($clock);

        $ping = $mcp->request('ping');

        self::assertSame([], McpTestClient::result($ping));
        self::assertSame(1, $clock->sleeps());
    }

    #[TestDox('A call that waits 30 seconds for its session gets 503')]
    public function testCallThatWaitsTooLongGets503(): void
    {
        [$mcp, $client, $secret] = $this->initializedSession();
        $runningRequest = self::lockInAnotherSession((string) $mcp->sessionId());
        $clock = new SleepHookClock(static function (): void {
        });
        Clock::set($clock);

        $response = self::sessionRequest($client, $secret, (string) $mcp->sessionId(), 'POST');

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertEqualsWithDelta(300, $clock->sleeps(), 1);
        $runningRequest->release();
    }

    #[TestDox('The base58 form of a session ID waits for the lock of its session')]
    public function testBase58SessionIdTakesTheLockOfItsSession(): void
    {
        [$mcp, $client, $secret] = $this->initializedSession();
        $runningRequest = self::lockInAnotherSession((string) $mcp->sessionId());
        Clock::set(new SleepHookClock(static function (): void {
        }));

        $response = self::sessionRequest($client, $secret, Uuid::fromString((string) $mcp->sessionId())->toBase58(), 'POST');

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $runningRequest->release();
    }

    /**
     * @return array{McpTestClient, KernelBrowser, string} The client, its browser, and its secret
     */
    private function initializedSession(): array
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $mcp = new McpTestClient($client, $secret);
        $mcp->initialize();

        return [$mcp, $client, $secret];
    }

    private static function lockInAnotherSession(string $sessionId): LockInterface
    {
        $lock = new LockFactory(LockStores::inAnotherSession(self::getContainer()))->createLock("mcp-session-{$sessionId}", ttl: null);
        self::assertTrue($lock->acquire());

        return $lock;
    }

    /**
     * A ping, or a DELETE, in the session.
     */
    private static function sessionRequest(KernelBrowser $client, #[\SensitiveParameter] string $secret, string $sessionId, string $method): Response
    {
        $client->request($method, '/_mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => "Bearer {$secret}",
            'HTTP_MCP_SESSION_ID' => $sessionId,
            'HTTP_MCP_PROTOCOL_VERSION' => McpTestClient::HANDSHAKE_VERSION,
        ], content: 'POST' === $method ? json_encode(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping'], \JSON_THROW_ON_ERROR) : null);

        return $client->getResponse();
    }
}
