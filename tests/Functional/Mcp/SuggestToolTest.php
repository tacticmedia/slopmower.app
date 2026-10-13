<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The flat anchor parameters of suggest_request, docs/specs/mcp.md section 4.17.
 */
final class SuggestToolTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('Anchor parameters without anchorKind, or anchorKind without path, give isError invalid_input')]
    public function testAnchorParametersMustAgreeWithAnchorKind(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $reviewId = self::startCodeReview($client);
        $mcp = new McpTestClient($client, self::storeToken($user)[1]);
        $body = 'Round half cents up.';

        $withoutKind = self::assertToolError($mcp->callTool('suggest_request', ['review' => $reviewId, 'body' => $body, 'path' => self::INVOICE_TOTALS, 'startLine' => 3]), 'invalid_input');
        $withoutPath = self::assertToolError($mcp->callTool('suggest_request', ['review' => $reviewId, 'body' => $body, 'anchorKind' => 'file']), 'invalid_input');

        self::assertSame(['path', 'startLine'], array_column($withoutKind['details']['violations'] ?? [], 'field'));
        self::assertSame(['path'], array_column($withoutPath['details']['violations'] ?? [], 'field'));
        self::assertSame(0, self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/requests"), 200, 'lists.schema.json#/$defs/RequestPage')['total']);
    }
}
