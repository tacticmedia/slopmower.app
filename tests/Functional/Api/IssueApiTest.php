<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/rest-api-issues.md. An agent writes the issues of the project SM through MCP. The signed-in user reads
 * them, comments, approves, and sets the status through REST.
 */
final class IssueApiTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use McpAssertions;
    use ResetDatabase;

    private const string PROBLEM = 'Invoice totals round each line. Done when a test with three lines of 0.335 gives 1.01.';
    private const string PLAN = '1. Add `TaxRounding::perInvoice()`. 2. Test three lines of 0.335.';
    private const string ISSUE = 'issue.schema.json';

    private KernelBrowser $client;

    private User $user;

    private string $secret;

    private McpTestClient $mcp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        [, $this->secret] = self::storeToken($this->user);
        $this->mcp = new McpTestClient($this->client, $this->secret);
    }

    #[TestDox('P1, I1, and I2 answer the projects, the issue summaries with the status filter, and the issue with its thread')]
    public function testReads(): void
    {
        $tenantId = $this->user->getTenantId();
        self::storeProject($tenantId, 'SM', repository: self::storeRepository($tenantId, 'alpha'));
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']);
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'problem' => self::PROBLEM, 'status' => 'todo']);

        $projects = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects'), 200, 'lists.schema.json#/$defs/ProjectPage');
        $issues = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/issues'), 200, 'lists.schema.json#/$defs/IssuePage');
        $todo = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/issues?status=todo'), 200, 'lists.schema.json#/$defs/IssuePage');
        $detail = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/issues/SM-2'), 200, 'issue.schema.json#/$defs/IssueDetail');

        self::assertSame([['key' => 'SM', 'name' => 'Project SM', 'approvalMode' => 'self', 'repositories' => ['alpha']]], $projects['items']);
        self::assertSame(['SM-2', 'SM-1'], array_column($issues['items'], 'key'), 'Newest change first.');
        self::assertSame(['SM-2'], array_column($todo['items'], 'key'));
        self::assertSame(['SM-2', self::PROBLEM, ['section_changed']], [$detail['issue']['key'], $detail['issue']['problem'], array_column($detail['thread'], 'kind')]);
    }

    #[TestDox('I3 writes a comment that get_issue shows with the actor kind user')]
    public function testCommentOfTheUser(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']);

        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => 'Keep `round()` as deprecated.']), 201, self::ISSUE);

        $thread = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'];
        self::assertSame([['comment', 'user', $this->user->getId()->toString(), 'Keep `round()` as deprecated.']], array_map(static fn (array $entry): array => [$entry['kind'], $entry['actor']['kind'], $entry['actor']['userId'], $entry['body']], $thread));
    }

    #[TestDox('I3 refuses an unknown label with 422 on label, and writes no comment')]
    public function testUnknownLabelIsRefused(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']);

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => 'Keep `round()` as deprecated.', 'label' => 'idea']), 422, 'invalid_input');

        self::assertSame(['label'], array_column($error['details']['violations'] ?? [], 'field'));
        self::assertSame([], $this->agent('get_issue', ['issue' => 'SM-1'])['thread']);
    }

    #[TestDox('I4 writes an approval that get_issue shows with the actor kind user and no reviewer')]
    public function testApprovalOfTheUser(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        $planRevision = $this->plannedIssue('todo');

        $approved = self::assertApiResponse($this->approve('SM-1', $planRevision), 200, self::ISSUE);

        $detail = $this->agent('get_issue', ['issue' => 'SM-1']);
        $entry = $detail['thread'][array_key_last($detail['thread'])];
        self::assertSame(['todo', 'user', null, null], [$approved['status'], $approved['planApproval']['approvedBy']['kind'], $approved['planApproval']['reviewer'], $approved['planApproval']['reviewNote']]);
        self::assertSame(['plan_approved', 'user', null, null, $planRevision], [$entry['kind'], $entry['actor']['kind'], $entry['reviewer'], $entry['reviewNote'], $entry['planRevision']]);
        self::assertSame($approved['planApproval'], $detail['issue']['planApproval']);
    }

    #[TestDox('I4 returns an issue from awaiting_user_input to todo, and keeps the status of an issue without a problem')]
    public function testApprovalReturnsAWaitingIssueToTodo(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::User);
        $withProblem = $this->plannedIssue('awaiting_user_input');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode']);
        $withoutProblem = (string) $this->agent('update_issue', ['issue' => 'SM-2', 'plan' => self::PLAN])['planRevision'];
        $this->agent('set_issue_status', ['issue' => 'SM-2', 'status' => 'awaiting_user_input']);

        $returned = self::assertApiResponse($this->approve('SM-1', $withProblem), 200, self::ISSUE);
        $kept = self::assertApiResponse($this->approve('SM-2', $withoutProblem), 200, self::ISSUE);

        $thread = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'];
        $entry = $thread[array_key_last($thread)];
        self::assertSame(['todo', 'awaiting_user_input'], [$returned['status'], $kept['status']]);
        self::assertSame(['status_changed', 'user', 'awaiting_user_input', 'todo'], [$entry['kind'], $entry['actor']['kind'], $entry['from'], $entry['to']]);
        self::assertSame('user', $kept['planApproval']['approvedBy']['kind'], 'The approval counts without the status change.');
    }

    #[TestDox('I4 refuses an old planRevision with 422 on planRevision, and stores no approval')]
    public function testOldPlanRevisionIsRefused(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $oldRevision = $this->plannedIssue('todo');
        $this->agent('update_issue', ['issue' => 'SM-1', 'plan' => self::PLAN.' 3. Update the README.']);

        $error = self::assertApiError($this->approve('SM-1', $oldRevision), 422, 'invalid_input');

        self::assertSame([['field' => 'planRevision', 'message' => 'The plan changed after this revision. Read the plan again, and approve its newest revision.']], $error['details']['violations'] ?? null);
        self::assertNull($this->agent('get_issue', ['issue' => 'SM-1'])['issue']['planApproval']);
    }

    #[TestDox('I4 refuses a token with 403')]
    public function testATokenCannotApprove(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $planRevision = $this->plannedIssue('todo');

        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/issues/SM-1/plan-approval', ['planRevision' => $planRevision]), 403, 'forbidden');

        self::assertSame('This endpoint needs a session. API tokens may not call it.', $error['message']);
    }

    #[TestDox('In the approval mode user, an agent sets in_progress only after the user approved the plan')]
    public function testAgentStartsAfterTheApprovalOfTheUser(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::User);
        $planRevision = $this->plannedIssue('todo');
        $claim = ['issue' => 'SM-1', 'status' => 'in_progress'];

        $before = self::assertToolError($this->mcp->callTool('set_issue_status', $claim), 'invalid_input');
        self::assertApiResponse($this->approve('SM-1', $planRevision), 200, self::ISSUE);
        $after = $this->agent('set_issue_status', $claim);

        self::assertSame('plan', $before['details']['violations'][0]['field'] ?? null);
        self::assertSame('in_progress', $after['status']);
    }

    #[TestDox('I8 by a user sets in_progress without an approval and done, sets done again without an entry, and leaves done')]
    public function testUserSetsEveryStatus(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::User);
        $this->plannedIssue('todo');
        $status = fn (string $to): Response => self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/status', ['status' => $to]);

        $started = self::assertApiResponse($status('in_progress'), 200, self::ISSUE);
        $withoutResult = self::assertApiError($status('done'), 422, 'invalid_input');
        self::assertApiResponse(self::apiRequest($this->client, 'PATCH', '/api/v1/issues/SM-1', ['result' => 'Added `TaxRounding::perInvoice()` in a1b2c3d.']), 200, self::ISSUE);
        $done = self::assertApiResponse($status('done'), 200, self::ISSUE);
        $again = self::assertApiResponse($status('done'), 200, self::ISSUE);
        $reopened = self::assertApiResponse($status('todo'), 200, self::ISSUE);

        $moves = array_values(array_filter($this->agent('get_issue', ['issue' => 'SM-1'])['thread'], static fn (array $entry): bool => 'status_changed' === $entry['kind']));
        self::assertSame(['in_progress', null, 'done', 'done', 'todo'], [$started['status'], $started['planApproval'], $done['status'], $again['status'], $reopened['status']]);
        self::assertSame([['field' => 'result', 'message' => 'An issue in done needs a result.']], $withoutResult['details']['violations'] ?? null);
        self::assertSame([['user', 'todo', 'in_progress'], ['user', 'in_progress', 'done'], ['user', 'done', 'todo']], array_map(static fn (array $entry): array => [$entry['actor']['kind'], $entry['from'], $entry['to']], $moves));
    }

    #[TestDox('A key that the caller cannot see answers 404 before the 403 and the body error, and a malformed key answers 404')]
    public function testHiddenKeyIsNotFoundFirst(): void
    {
        $other = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        self::storeProject($other->getTenantId(), 'DOC');
        $otherMcp = new McpTestClient($this->client, self::storeToken($other)[1]);
        self::assertToolResult($otherMcp, $otherMcp->callTool('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding']), 'create_issue');
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']);

        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/projects/DOC/issues?limit=0'), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/issues/DOC-1/comments', []), 404, 'not_found');
        self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/issues/DOC-1/plan-approval', []), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/issues/sm-1'), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/issues?limit=0'), 422, 'invalid_input');
    }

    /**
     * SM-1 with a problem and a plan, in $status.
     *
     * @return string The plan revision
     */
    private function plannedIssue(string $status): string
    {
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $planRevision = (string) $this->agent('update_issue', ['issue' => 'SM-1', 'plan' => self::PLAN])['planRevision'];

        if ('todo' !== $status) {
            $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => $status]);
        }

        return $planRevision;
    }

    private function approve(string $issueKey, string $planRevision): Response
    {
        return self::apiRequest($this->client, 'POST', "/api/v1/issues/{$issueKey}/plan-approval", ['planRevision' => $planRevision]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The structuredContent of the tool call of the agent
     */
    private function agent(string $tool, array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $arguments), $tool);
    }
}
