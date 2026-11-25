<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Domain\Issue\ApprovalMode;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/specs/mcp.md section 4.29 and I10 of docs/specs/rest-api-issues.md. The agent of a token works on the project SM,
 * and the user writes through the session.
 */
final class IssueUpdatesTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use McpAssertions;
    use ResetDatabase;

    private const string PROBLEM = 'Invoice totals round each line. Done when a test with three lines of 0.335 gives 1.01.';
    private const string PLAN = '1. Add `TaxRounding::perInvoice()`. 2. Test three lines of 0.335.';

    private KernelBrowser $client;

    private User $user;

    private McpTestClient $mcp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = TenantFixtures::of(self::getContainer())->create();
        $this->mcp = new McpTestClient($this->client, self::storeToken($this->user)[1]);
        $this->client->loginUser($this->user, 'web');
    }

    #[TestDox('With a cursor, the agent gets the comment, the answers, and the plan approval of the user, without its own entries and a replaced entry, and the user gets only the entries of the agent')]
    public function testAgentGetsTheWritesOfTheUser(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::User);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $planRevision = $this->call('update_issue', ['issue' => 'SM-1', 'plan' => self::PLAN])['planRevision'];
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'One choice before the work.', 'questions' => [['question' => 'Remove `round()` in 3.0?']]]);
        $this->call('set_issue_status', ['issue' => 'SM-1', 'status' => 'awaiting_user_input']);
        $start = $this->call('get_issue_updates', ['project' => 'SM']);
        $question = $this->call('list_comments', ['project' => 'SM', 'label' => 'question'])['items'][0]['id'];

        $this->userWrites('POST', 'comments', ['body' => 'Use Money for the amounts.']);
        $this->userWrites('POST', 'comments', ['body' => '', 'replaces' => $question, 'questions' => [['question' => 'Remove `round()` in 3.0?', 'answer' => 'Yes']]]);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'I wait for the approval.']);
        $this->userWrites('PATCH', '', ['problem' => self::PROBLEM.' Also for credit notes.']);
        $this->userWrites('PATCH', '', ['problem' => self::PROBLEM.' Also for credit notes and refunds.']);
        $approved = $this->userWrites('POST', 'plan-approval', ['planRevision' => $planRevision]);
        $updates = $this->call('get_issue_updates', ['project' => 'SM', 'cursor' => $start['cursor']]);
        $ofUser = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/projects/SM/updates?cursor={$start['cursor']}"), 200, 'issue.schema.json#/$defs/IssueUpdatesPage');
        $row = static fn (array $item): array => [$item['entry']['kind'], $item['entry']['label'], $item['entry']['actor']['kind'], $item['entry']['body'], $item['entry']['to']];

        self::assertSame(['cursor' => 4, 'hasMore' => false, 'issues' => [['key' => 'SM-1', 'title' => 'Round invoice totals once', 'status' => 'awaiting_user_input', 'updatedAt' => $start['issues'][0]['updatedAt']]], 'entries' => []], $start);
        self::assertSame([
            ['comment', 'comment', 'user', 'Use Money for the amounts.', null],
            ['comment', 'answer', 'user', '', null],
            ['section_changed', null, 'user', null, null],
            ['plan_approved', null, 'user', null, null],
            ['status_changed', null, 'user', null, 'todo'],
        ], array_map($row, $updates['entries']));
        self::assertSame([[['question' => 'Remove `round()` in 3.0?', 'detail' => null, 'answer' => 'Yes']], $question], [$updates['entries'][1]['entry']['questions'], $updates['entries'][1]['entry']['replaces']]);
        self::assertSame(['SM-1', 'todo', $approved['updatedAt']], [$updates['entries'][0]['issue']['key'], $updates['entries'][0]['issue']['status'], $updates['entries'][0]['issue']['updatedAt']], 'The issue as it is now.');
        self::assertSame([11, false, []], [$updates['cursor'], $updates['hasMore'], $updates['issues']]);
        self::assertSame([['comment', 'comment', 'agent', 'I wait for the approval.', null]], array_map($row, $ofUser['entries']));
    }

    #[TestDox('Entries of two issues take rising numbers, and hasMore pages them with the cursor of the last entry read')]
    public function testPagesAcrossIssues(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode']);
        $start = $this->call('get_issue_updates', ['project' => 'SM'])['cursor'];

        foreach (['SM-1', 'SM-2', 'SM-1'] as $index => $issueKey) {
            self::apiRequest($this->client, 'POST', "/api/v1/issues/{$issueKey}/comments", ['body' => "Comment {$index}."]);
        }

        $first = $this->call('get_issue_updates', ['project' => 'SM', 'cursor' => $start, 'limit' => 2]);
        $second = $this->call('get_issue_updates', ['project' => 'SM', 'cursor' => $first['cursor'], 'limit' => 2]);
        $row = static fn (array $item): array => [$item['issue']['key'], $item['entry']['body']];

        self::assertSame([0, true, [['SM-1', 'Comment 0.'], ['SM-2', 'Comment 1.']], 2], [$start, $first['hasMore'], array_map($row, $first['entries']), $first['cursor']]);
        self::assertSame([false, [['SM-1', 'Comment 2.']], 3], [$second['hasMore'], array_map($row, $second['entries']), $second['cursor']]);
    }

    #[TestDox('Without a cursor, the issues in work come newest first with the newest cursor, also of a project without entries, and a cursor ahead of the server is refused')]
    public function testFirstCallAndACursorAhead(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        self::storeProject($this->user->getTenantId(), 'DOC');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'A draft']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'A duplicate']);
        $this->call('set_issue_status', ['issue' => 'SM-3', 'status' => 'wont_do', 'note' => 'SM-2 covers it.']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $this->call('set_issue_status', ['issue' => 'SM-4', 'status' => 'awaiting_user_input']);

        $first = $this->call('get_issue_updates', ['project' => 'SM']);
        $empty = $this->call('get_issue_updates', ['project' => 'DOC']);
        $ahead = self::assertToolError($this->mcp->callTool('get_issue_updates', ['project' => 'SM', 'cursor' => 5]), 'invalid_input');

        self::assertSame([4, false, ['SM-4', 'SM-2'], []], [$first['cursor'], $first['hasMore'], array_column($first['issues'], 'key'), $first['entries']]);
        self::assertSame(['cursor' => 0, 'hasMore' => false, 'issues' => [], 'entries' => []], $empty);
        self::assertSame([['field' => 'cursor', 'message' => 'Is ahead of the server, whose newest entry of the project is 4.']], $ahead['details']['violations'] ?? null);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The structuredContent, checked against the output schema of the tool
     */
    private function call(string $tool, array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $arguments), $tool);
    }

    /**
     * A write of the user on SM-1 through the session.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> The issue
     */
    private function userWrites(string $method, string $path, array $body): array
    {
        $response = self::apiRequest($this->client, $method, '/api/v1/issues/SM-1'.('' === $path ? '' : "/{$path}"), $body);

        return self::assertApiResponse($response, 'comments' === $path ? 201 : 200, 'issue.schema.json');
    }
}
