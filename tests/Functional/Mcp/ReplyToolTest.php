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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The user opened a request through the API, and the agent claimed it through MCP.
 */
final class ReplyToolTest extends WebTestCase
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

    private KernelBrowser $client;

    private McpTestClient $mcp;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->requestId = (string) self::openRequest($this->client, self::startCodeReview($this->client))['id'];
        $this->mcp = new McpTestClient($this->client, self::storeToken($user)[1]);
        self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');
    }

    #[TestDox('question true hands the turn to the user')]
    public function testQuestionHandsTurnToUser(): void
    {
        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('reply_to_request', ['id' => $this->requestId, 'body' => 'Should the tax lines use `Money` too?', 'question' => true]), 'reply_to_request');

        self::assertSame(['in_progress', 'user', 1], [$result['request']['status'], $result['request']['awaiting'], $result['request']['commentCount']]);
        self::assertSame([['request.commented', 'Should the tax lines use `Money` too?', true]], array_map(static fn (array $event): array => [$event['type'], $event['body'], $event['question']], $result['events']));
    }

    #[TestDox("The user's reply hands the turn back to the agent")]
    public function testUserReplyHandsTurnBackToAgent(): void
    {
        self::assertToolResult($this->mcp, $this->mcp->callTool('reply_to_request', ['id' => $this->requestId, 'body' => 'Should the tax lines use `Money` too?', 'question' => true]), 'reply_to_request');

        $result = self::requestCommand($this->client, $this->requestId, 'comments', ['body' => 'Yes, the tax lines too.']);

        self::assertSame(['in_progress', 'agent'], [$result['request']['status'], $result['request']['awaiting']]);
    }

    #[TestDox('questions are stored unanswered, hand the turn to the user, and show in get_request')]
    public function testQuestionsShowInGetRequest(): void
    {
        $questions = [['question' => 'Should the tax lines use `Money` too?', 'detail' => 'They use `float` today.'], ['question' => 'Keep `round()`?']];

        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('reply_to_request', ['id' => $this->requestId, 'body' => 'Two questions.', 'questions' => $questions]), 'reply_to_request');
        $thread = self::assertToolResult($this->mcp, $this->mcp->callTool('get_request', ['id' => $this->requestId]), 'get_request')['thread'];

        $expected = [['question' => 'Should the tax lines use `Money` too?', 'detail' => 'They use `float` today.', 'answer' => null], ['question' => 'Keep `round()`?', 'detail' => null, 'answer' => null]];
        self::assertSame(['user', true, $expected], [$result['request']['awaiting'], $result['events'][0]['question'], $result['events'][0]['questions']]);
        self::assertSame($expected, end($thread)['questions']);
    }

    #[TestDox("The user's answers with an empty body give 201 and hand the turn back to the agent")]
    public function testUserAnswersHandTurnBackToAgent(): void
    {
        self::assertToolResult($this->mcp, $this->mcp->callTool('reply_to_request', ['id' => $this->requestId, 'body' => 'One question.', 'questions' => [['question' => 'Keep `round()`?']]]), 'reply_to_request');
        $answers = [['question' => 'Keep `round()`?', 'answer' => 'Yes']];

        $result = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$this->requestId}/comments", ['body' => '', 'questions' => $answers]), 201, 'request-command-result.schema.json');

        self::assertSame(['agent', '', [['question' => 'Keep `round()`?', 'detail' => null, 'answer' => 'Yes']]], [$result['request']['awaiting'], $result['events'][0]['body'], $result['events'][0]['questions']]);
    }

    #[TestDox('A whitespace-only body gives isError invalid_input')]
    public function testWhitespaceOnlyBodyGivesInvalidInput(): void
    {
        $error = self::assertToolError($this->mcp->callTool('reply_to_request', ['id' => $this->requestId, 'body' => " \n\t "]), 'invalid_input');

        self::assertSame('body', $error['details']['violations'][0]['field'] ?? null);
    }
}
