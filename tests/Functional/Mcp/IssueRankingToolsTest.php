<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rank order and the activity of D137 in docs/specs/mcp.md sections 4.20 and 4.22. An agent works on the issues of
 * the project SM, and the signed-in user moves and approves through REST.
 */
final class IssueRankingToolsTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use JsonApiAssertions;
    use McpAssertions;
    use ResetDatabase;

    private const string PROBLEM = 'Invoice totals round each line. Done when a test with three lines of 0.335 gives 1.01.';
    private const string SESSION = 'billing-3f9a';

    private KernelBrowser $client;

    private User $user;

    private McpTestClient $mcp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->mcp = new McpTestClient($this->client, self::storeToken($this->user)[1]);
        self::storeProject($this->user->getTenantId(), 'SM');
    }

    #[TestDox('create_issue passes the priority of the agent: urgent places the new change among the first open changes')]
    public function testAgentGivesThePriority(): void
    {
        foreach (['change', 'change', 'change', 'bug'] as $category) {
            $this->createIssue($category);
        }

        $urgent = $this->createIssue('change', ['priority' => 'urgent']);

        self::assertSame(['SM-4', 'SM-1', 'SM-5', 'SM-2', 'SM-3'], $this->keys([]));
        self::assertSame('SM-5', $urgent);
    }

    #[TestDox('list_issues lists the open bugs by rank, then the open changes by rank, and with a status only that status')]
    public function testDefaultOrder(): void
    {
        foreach (['change', 'bug', 'change', 'bug'] as $category) {
            $this->createIssue($category);
        }

        $this->call('set_issue_status', ['issue' => 'SM-3', 'status' => 'wont_do', 'note' => 'SM-1 covers it.']);
        self::assertSame(200, $this->move('SM-4', ['before' => 'SM-2'])->getStatusCode());

        self::assertSame(['SM-4', 'SM-2', 'SM-1'], $this->keys([]));
        self::assertSame(['SM-3'], $this->keys(['status' => 'wont_do']));
    }

    #[TestDox('list_issues with sort updated lists every issue, newest updatedAt first')]
    public function testSortByUpdate(): void
    {
        self::mockTime('2026-11-25 09:00:00');
        $this->createIssue('change');
        $this->createIssue('bug');
        $this->createIssue('change');
        self::mockTime('2026-11-25 10:00:00');
        $this->call('set_issue_status', ['issue' => 'SM-1', 'status' => 'wont_do', 'note' => 'SM-3 covers it.']);
        self::mockTime('2026-11-25 11:00:00');
        $this->call('update_issue', ['issue' => 'SM-3', 'title' => 'Round the shipping totals once']);

        self::assertSame(['SM-3', 'SM-1', 'SM-2'], $this->keys(['sort' => 'updated']));
    }

    #[TestDox('A comment, a status change, a section version, and a plan approval set lastActivityAt, and a creation, a title, a category, a link, and a move do not')]
    public function testActivity(): void
    {
        self::mockTime('2026-11-25 09:00:00');
        $this->createIssue('change', ['problem' => self::PROBLEM, 'status' => 'todo']);
        $this->createIssue('change');
        $this->call('update_issue', ['issue' => 'SM-1', 'title' => 'Round invoice totals once per invoice', 'category' => 'bug']);
        $this->call('update_issue', ['issue' => 'SM-2', 'category' => 'bug']);
        $this->call('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => 'SM-1 rounds the totals.']);
        $this->move('SM-2', ['before' => 'SM-1']);
        $activity = ['none' => $this->lastActivity()];
        self::mockTime('2026-11-25 10:00:00');
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'The test is next.']);
        $activity['comment'] = $this->lastActivity();
        self::mockTime('2026-11-25 11:00:00');
        $this->call('set_issue_status', ['issue' => 'SM-1', 'status' => 'todo', 'waitingFor' => 'requester']);
        $activity['status'] = $this->lastActivity();
        self::mockTime('2026-11-25 12:00:00');
        $planRevision = $this->call('update_issue', ['issue' => 'SM-1', 'plan' => '1. Add `TaxRounding::perInvoice()`.'])['planRevision'];
        $activity['section'] = $this->lastActivity();
        self::mockTime('2026-11-25 13:00:00');
        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/plan-approval', ['planRevision' => $planRevision]), 200, 'issue.schema.json');
        $activity['approval'] = $this->lastActivity();

        self::assertSame(['none' => null, 'comment' => '2026-11-25T10:00:00Z', 'status' => '2026-11-25T11:00:00Z', 'section' => '2026-11-25T12:00:00Z', 'approval' => '2026-11-25T13:00:00Z'], $activity);
    }

    #[TestDox('Each activity filter cuts at now minus its hours: activity at the cutoff is active, and an issue without activity counts from its creation')]
    public function testActivityFilters(): void
    {
        self::mockTime('2026-11-25 09:00:00');
        $this->createIssue('change');
        $this->createIssue('change');
        $this->createIssue('change');
        self::mockTime('2026-11-25 10:00:00');
        $this->call('comment_on_issue', ['issue' => 'SM-2', 'body' => 'Started.']);
        self::mockTime('2026-11-25 12:00:00');
        $this->call('comment_on_issue', ['issue' => 'SM-3', 'body' => 'Started.']);
        self::mockTime('2026-11-25 13:30:00');
        $this->createIssue('change');
        self::mockTime('2026-11-25 14:00:00');

        self::assertSame(
            [['SM-3'], ['SM-2', 'SM-3'], ['SM-1', 'SM-2'], [], ['SM-1', 'SM-4']],
            [
                $this->keys(['activeWithinHours' => 2]),
                $this->keys(['activeWithinHours' => 4]),
                $this->keys(['idleForHours' => 2]),
                $this->keys(['idleForHours' => 5]),
                $this->keys(['noActivity' => true]),
            ],
        );
    }

    #[TestDox('list_issues refuses a second activity filter on its field')]
    public function testTwoFiltersAreRefused(): void
    {
        self::assertSame(['idleForHours', 'noActivity'], [
            $this->refusal(['activeWithinHours' => 24, 'idleForHours' => 72]),
            $this->refusal(['idleForHours' => 72, 'noActivity' => true]),
        ]);
    }

    #[TestDox('The next page follows the last bug with the first change and keeps the cutoff of the first page, and a cursor of another filter or sort is refused')]
    public function testPagesKeepTheCutoff(): void
    {
        self::mockTime('2026-11-25 10:00:00');

        foreach (['change', 'bug', 'change'] as $category) {
            $key = $this->createIssue($category);
            $this->call('comment_on_issue', ['issue' => $key, 'body' => 'Started.']);
        }

        self::mockTime('2026-11-25 10:30:00');
        $first = $this->call('list_issues', ['project' => 'SM', 'activeWithinHours' => 1, 'limit' => 1]);
        // From now, the cutoff would be 10:30, after every comment.
        self::mockTime('2026-11-25 11:30:00');
        $second = $this->call('list_issues', ['project' => 'SM', 'activeWithinHours' => 1, 'limit' => 1, 'cursor' => $first['nextCursor']]);
        $third = $this->call('list_issues', ['project' => 'SM', 'activeWithinHours' => 1, 'limit' => 1, 'cursor' => $second['nextCursor']]);

        self::assertSame([['SM-2'], ['SM-1'], ['SM-3'], null], [array_column($first['items'], 'key'), array_column($second['items'], 'key'), array_column($third['items'], 'key'), $third['nextCursor']]);
        self::assertSame(['cursor', 'cursor'], [
            $this->refusal(['activeWithinHours' => 2, 'limit' => 1, 'cursor' => $first['nextCursor']]),
            $this->refusal(['sort' => 'updated', 'activeWithinHours' => 1, 'limit' => 1, 'cursor' => $first['nextCursor']]),
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return string The key of the new issue of the agent
     */
    private function createIssue(string $category, array $fields = []): string
    {
        return (string) $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => $category] + $fields)['key'];
    }

    /**
     * I13 of the signed-in user, which has no tool.
     *
     * @param array<string, string> $body
     */
    private function move(string $issueKey, array $body): Response
    {
        return self::apiRequest($this->client, 'POST', "/api/v1/issues/{$issueKey}/rank", $body);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    private function keys(array $filters): array
    {
        return array_column($this->call('list_issues', ['project' => 'SM'] + $filters)['items'], 'key');
    }

    private function lastActivity(): ?string
    {
        $issue = $this->call('get_issue', ['issue' => 'SM-1'])['issue'];
        $summary = $this->call('list_issues', ['project' => 'SM', 'status' => $issue['status']])['items'];
        self::assertSame([$issue['lastActivityAt']], array_column(array_filter($summary, static fn (array $item): bool => 'SM-1' === $item['key']), 'lastActivityAt'), 'The summary has the time of the issue.');

        return $issue['lastActivityAt'];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return string The field of the one violation of list_issues
     */
    private function refusal(array $filters): string
    {
        $violations = self::assertToolError($this->mcp->callTool('list_issues', ['project' => 'SM'] + $filters), 'invalid_input')['details']['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertCount(1, $violations);

        return $violations[0]['field'];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The structuredContent, checked against the output schema of the tool
     */
    private function call(string $tool, array $arguments): array
    {
        $withSession = \in_array($tool, ['update_issue', 'set_issue_status', 'comment_on_issue'], true) ? $arguments + ['session' => self::SESSION] : $arguments;

        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $withSession), $tool);
    }
}
