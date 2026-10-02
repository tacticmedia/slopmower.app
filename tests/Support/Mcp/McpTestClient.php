<?php

declare(strict_types=1);

namespace App\Tests\Support\Mcp;

use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\BrowserKit\Response;

/**
 * MCP JSON-RPC over Streamable HTTP, through a KernelBrowser or an HttpBrowser. Reads JSON and SSE responses. In the
 * handshake era it keeps `Mcp-Session-Id` and sends `MCP-Protocol-Version`.
 */
final class McpTestClient
{
    public const string HANDSHAKE_VERSION = '2025-11-25';
    public const string MODERN_VERSION = '2026-07-28';

    private ?string $sessionId = null;

    private ?string $protocolVersion = null;

    private int $nextId = 1;

    /** The raw JSON of the last JSON-RPC message that the server answered with. */
    private string $lastMessageJson = '';

    /** @var array<string, \stdClass>|null The tools of tools/list by name */
    private ?array $tools = null;

    public function __construct(
        private readonly KernelBrowser|HttpBrowser $browser,
        #[\SensitiveParameter] private readonly ?string $secret,
        private readonly string $endpoint = '/_mcp',
    ) {
    }

    /**
     * The handshake: initialize, then the initialized notification.
     *
     * @return array<string, mixed> The initialize result
     */
    public function initialize(string $protocolVersion = self::HANDSHAKE_VERSION): array
    {
        $id = $this->nextId;
        $result = self::result($this->responseTo($id, $this->initializeResponse($protocolVersion)));
        $sessionId = $this->lastResponse()->getHeader('Mcp-Session-Id');
        Assert::assertIsString($sessionId, 'initialize answers with an Mcp-Session-Id header.');
        $this->sessionId = $sessionId;
        Assert::assertIsString($result['protocolVersion']);
        $this->protocolVersion = $result['protocolVersion'];
        Assert::assertSame(202, $this->notify('notifications/initialized'));

        return $result;
    }

    /**
     * Sends initialize and returns the HTTP response as it is, for example a 401.
     */
    public function initializeResponse(string $protocolVersion = self::HANDSHAKE_VERSION): Response
    {
        $this->post(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'initialize', 'params' => [
            'protocolVersion' => $protocolVersion,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'holistic-review-tests', 'version' => '1.0.0'],
        ]], []);

        return $this->lastResponse();
    }

    /**
     * A request of the handshake era, in the session. Initializes one first when there is none.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> The JSON-RPC response
     */
    public function request(string $method, array $params = []): array
    {
        if (null === $this->sessionId) {
            $this->initialize();
        }

        return $this->send(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params]);
    }

    /**
     * @return int The HTTP status
     */
    public function notify(string $method): int
    {
        $this->post(['jsonrpc' => '2.0', 'method' => $method], []);

        return $this->lastResponse()->getStatusCode();
    }

    /**
     * A request of the 2026-07-28 era: no session, the version in `_meta` and in the headers.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> The JSON-RPC response
     */
    public function modernRequest(string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];

        return $this->send(
            ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method, 'params' => $params],
            ['HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_VERSION, 'HTTP_MCP_METHOD' => $method],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The tools/call result
     */
    public function callTool(string $name, array $arguments = []): array
    {
        return self::result($this->callToolResponse($name, $arguments));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The JSON-RPC response, which can be an error
     */
    public function callToolResponse(string $name, array $arguments = []): array
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]);
    }

    /**
     * Object-decoded, so an empty JSON object stays one when it is encoded again.
     */
    public function tool(string $name): \stdClass
    {
        if (null === $this->tools) {
            $this->request('tools/list');
            $message = json_decode($this->lastMessageJson, flags: \JSON_THROW_ON_ERROR);
            Assert::assertInstanceOf(\stdClass::class, $message);
            $this->tools = [];

            foreach ($message->result->tools as $tool) {
                $this->tools[$tool->name] = $tool;
            }
        }

        return $this->tools[$name] ?? Assert::fail("tools/list has no tool {$name}.");
    }

    /**
     * The JSON of a member of the last result, with empty objects intact.
     */
    public function lastResultMemberJson(string $member): string
    {
        $message = json_decode($this->lastMessageJson, flags: \JSON_THROW_ON_ERROR);
        Assert::assertInstanceOf(\stdClass::class, $message);
        Assert::assertObjectHasProperty($member, $message->result);

        return json_encode($message->result->{$member}, \JSON_THROW_ON_ERROR);
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function lastResponse(): Response
    {
        return $this->browser->getInternalResponse();
    }

    /**
     * @param array<string, mixed> $response A JSON-RPC response
     *
     * @return array<string, mixed>
     */
    public static function result(array $response): array
    {
        Assert::assertArrayHasKey('result', $response, 'A JSON-RPC result, not an error: '.json_encode($response));
        Assert::assertIsArray($response['result']);

        return $response['result'];
    }

    /**
     * @param array{id: int, ...}   $message
     * @param array<string, string> $headers
     *
     * @return array<string, mixed> The JSON-RPC response with the ID of the message
     */
    private function send(array $message, array $headers = []): array
    {
        $this->post($message, $headers);

        return $this->responseTo($message['id'], $this->lastResponse());
    }

    /**
     * @return array<string, mixed> The JSON-RPC response with the ID
     */
    private function responseTo(int $id, Response $response): array
    {
        Assert::assertSame(200, $response->getStatusCode(), $response->getContent());

        foreach (self::messages($response) as $json) {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

            if (\is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                $this->lastMessageJson = $json;

                return $decoded;
            }
        }

        return Assert::fail("No response to the message {$id}: {$response->getContent()}");
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function post(array $message, array $headers): void
    {
        $server = $headers + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        if (null !== $this->secret) {
            $server['HTTP_AUTHORIZATION'] = "Bearer {$this->secret}";
        }

        if (null !== $this->sessionId && !isset($headers['HTTP_MCP_PROTOCOL_VERSION'])) {
            $server['HTTP_MCP_SESSION_ID'] = $this->sessionId;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = (string) $this->protocolVersion;
        }

        $this->browser->request('POST', $this->endpoint, server: $server, content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string> The JSON of each message: the body, or the data of each SSE event
     */
    private static function messages(Response $response): array
    {
        $content = $response->getContent();
        $contentType = $response->getHeader('Content-Type');

        if (!\is_string($contentType) || !str_starts_with($contentType, 'text/event-stream')) {
            return [$content];
        }

        preg_match_all('/^data: (.*)$/m', $content, $matches);

        return $matches[1];
    }
}
