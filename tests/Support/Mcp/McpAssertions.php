<?php

declare(strict_types=1);

namespace App\Tests\Support\Mcp;

use App\Tests\Support\Contract\SchemaValidator;

/**
 * Checks tools/call results against docs/specs/mcp.md: the outputSchema of the tool, the schema file it bundles, and
 * the error mapping of section 5.
 */
trait McpAssertions
{
    /** The schema file behind the outputSchema of each tool, docs/specs/mcp.md section 4. */
    private const array TOOL_SCHEMAS = [
        'list_repositories' => 'lists.schema.json#/$defs/RepositoryPage',
        'list_reviews' => 'lists.schema.json#/$defs/ReviewPage',
        'list_requests' => 'lists.schema.json#/$defs/RequestPage',
        'get_updates' => 'updates-page.schema.json',
        'get_request' => 'request.schema.json',
    ];

    /**
     * Reads the outputSchema through tools/list, so call it right after the tools/call.
     *
     * @param array<string, mixed> $result The last tools/call result of $mcp
     *
     * @return array<string, mixed> The structuredContent
     */
    private static function assertToolResult(McpTestClient $mcp, array $result, string $tool): array
    {
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));
        self::assertIsArray($result['structuredContent'] ?? null);
        $structuredContent = $mcp->lastResultMemberJson('structuredContent');
        $outputSchema = json_encode($mcp->tool($tool)->outputSchema, \JSON_THROW_ON_ERROR);

        self::assertSame([], SchemaValidator::errorsAgainst($structuredContent, $outputSchema), "structuredContent does not match the outputSchema of {$tool}: {$structuredContent}");
        self::assertSame([], SchemaValidator::errors($structuredContent, self::TOOL_SCHEMAS[$tool]), 'structuredContent does not match '.self::TOOL_SCHEMAS[$tool].": {$structuredContent}");

        return $result['structuredContent'];
    }

    /**
     * @param array<string, mixed> $result A tools/call result
     *
     * @return array{code: string, message: string, details: array<string, mixed>|null}
     */
    private static function assertToolError(array $result, string $code): array
    {
        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertIsArray($result['content']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        $text = $result['content'][0]['text'];
        self::assertIsString($text);
        self::assertSame([], SchemaValidator::errors($text, 'error.schema.json'), "The text is no Error JSON: {$text}");
        /** @var array{error: array{code: string, message: string, details: array<string, mixed>|null}} $body */
        $body = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($code, $body['error']['code']);

        return $body['error'];
    }

    /**
     * @param array<string, mixed> $response A JSON-RPC response
     *
     * @return array<string, mixed> The JSON-RPC error
     */
    private static function assertJsonRpcError(array $response, int $code): array
    {
        self::assertArrayNotHasKey('result', $response);
        self::assertIsArray($response['error'] ?? null, (string) json_encode($response));
        self::assertSame($code, $response['error']['code']);

        return $response['error'];
    }
}
