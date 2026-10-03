<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use App\Tests\Support\Mcp\McpTestClient;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

/**
 * MCP JSON-RPC from the PHPUnit process to the e2e site, which the browser of a {@see FullStackTestCase} uses.
 */
final class McpHttpClient
{
    public static function connect(#[\SensitiveParameter] string $secret): McpTestClient
    {
        return new McpTestClient(new HttpBrowser(HttpClient::create()), $secret, FullStackTestCase::BASE_URI.'/_mcp');
    }
}
