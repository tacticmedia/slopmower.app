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
    private const string SESSION = 'billing-3f9a';

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
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);

        $projects = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects'), 200, 'lists.schema.json#/$defs/ProjectPage');
        $issues = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/issues'), 200, 'lists.schema.json#/$defs/IssuePage');
        $todo = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/issues?status=todo'), 200, 'lists.schema.json#/$defs/IssuePage');
        $detail = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/issues/SM-2'), 200, 'issue.schema.json#/$defs/IssueDetail');

        self::assertSame([['key' => 'SM', 'name' => 'Project SM', 'approvalMode' => 'self', 'approvalGuidelines' => null, 'repositories' => ['alpha']]], $projects['items']);
        self::assertSame(['SM-2', 'SM-1'], array_column($issues['items'], 'key'), 'Newest change first.');
        self::assertSame(['SM-2'], array_column($todo['items'], 'key'));
        self::assertSame(['SM-2', self::PROBLEM, ['section_changed']], [$detail['issue']['key'], $detail['issue']['problem'], array_column($detail['thread'], 'kind')]);
    }

    #[TestDox('I3 writes a comment that get_issue shows with the actor kind user')]
    public function testCommentOfTheUser(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);

        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => 'Keep `round()` as deprecated.']), 201, self::ISSUE);

        $thread = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'];
        self::assertSame([['comment', 'user', $this->user->getId()->toString(), 'Keep `round()` as deprecated.']], array_map(static fn (array $entry): array => [$entry['kind'], $entry['actor']['kind'], $entry['actor']['userId'], $entry['body']], $thread));
    }

    #[TestDox('I3 with replaces replaces a comment of an agent and of a user, and I2 with history=true answers each replaced entry with its text')]
    public function testReplacesAndHistory(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $firstPlan = $this->plannedIssue('todo');
        $this->agent('update_issue', ['issue' => 'SM-1', 'plan' => self::PLAN.' 3. Update the README.']);
        $this->agent('comment_on_issue', ['issue' => 'SM-1', 'body' => '`round()` goes.']);
        $comment = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'][2]['id'];
        $row = static fn (array $entry): array => [$entry['kind'], $entry['body'], $entry['replaces']];

        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => 'Keep `round()` as deprecated.', 'replaces' => $comment]), 201, self::ISSUE);
        $current = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/issues/SM-1'), 200, 'issue.schema.json#/$defs/IssueDetail')['thread'];
        $history = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/issues/SM-1?history=true'), 200, 'issue.schema.json#/$defs/IssueDetail')['thread'];

        self::assertSame([['section_changed', null, null], ['section_changed', null, $firstPlan], ['comment', 'Keep `round()` as deprecated.', $comment]], array_map($row, $current));
        self::assertSame(
            [['section_changed', self::PROBLEM, null], ['section_changed', self::PLAN, null], ['section_changed', self::PLAN.' 3. Update the README.', $firstPlan], ['comment', '`round()` goes.', null], ['comment', 'Keep `round()` as deprecated.', $comment]],
            array_map($row, $history),
        );
        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => 'Remove `round()` in 3.0.', 'replaces' => $current[2]['id']]), 201, self::ISSUE);
    }

    #[TestDox('I3 with answers replaces the open question comment with the label answer and each detail, get_issue has the trimmed pairs, I5 lists only the answer, and the answer takes no answers')]
    public function testUserAnswersQuestions(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $asked = $this->ask();
        $answers = [['question' => ' Remove `round()` in 3.0? ', 'answer' => ' Yes '], ['question' => 'Which type holds the amounts?', 'answer' => 'Money']];

        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'label' => 'decision', 'replaces' => $asked, 'questions' => $answers]), 201, self::ISSUE);

        $thread = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'];
        $comments = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/comments'), 200, 'lists.schema.json#/$defs/IssueCommentPage')['items'];
        $pairs = [['question' => 'Remove `round()` in 3.0?', 'detail' => 'BILL-9 still calls it.', 'answer' => 'Yes'], ['question' => 'Which type holds the amounts?', 'detail' => null, 'answer' => 'Money']];
        self::assertSame([['answer', 'user', '', $asked, $pairs]], array_map(static fn (array $entry): array => [$entry['label'], $entry['actor']['kind'], $entry['body'], $entry['replaces'], $entry['questions']], $thread));
        self::assertSame([[$thread[0]['id'], 'answer', $pairs]], array_map(static fn (array $comment): array => [$comment['id'], $comment['label'], $comment['questions']], $comments));
        $again = self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'replaces' => $thread[0]['id'], 'questions' => $answers]), 422, 'invalid_input');
        self::assertSame(['replaces'], array_column($again['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('I3 refuses answers from a token, without replaces, that do not repeat the questions, to a comment that is no open question comment, and without an answer')]
    public function testAnswerRefusals(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->agent('comment_on_issue', ['issue' => 'SM-1', 'body' => 'The test is next.']);
        $plain = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'][0]['id'];
        $asked = $this->ask();
        $answers = [['question' => 'Remove `round()` in 3.0?', 'answer' => 'Yes'], ['question' => 'Which type holds the amounts?', 'answer' => 'Money']];
        $field = fn (Response $response): ?string => self::assertApiError($response, 422, 'invalid_input')['details']['violations'][0]['field'] ?? null;
        $post = fn (array $body): Response => self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => ''] + $body);

        self::assertSame(
            ['questions.0.answer', 'replaces', 'questions', 'replaces', 'questions.1.answer'],
            [
                $field(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'replaces' => $asked, 'questions' => $answers, 'session' => self::SESSION])),
                $field($post(['questions' => $answers])),
                $field($post(['replaces' => $asked, 'questions' => array_reverse($answers)])),
                $field($post(['replaces' => $plain, 'questions' => $answers])),
                $field($post(['replaces' => $asked, 'questions' => [$answers[0], ['question' => 'Which type holds the amounts?']]])),
            ],
        );
        self::assertSame([$plain, $asked], array_column($this->agent('get_issue', ['issue' => 'SM-1', 'history' => true])['thread'], 'id'), 'A refused answer is not stored.');
    }

    #[TestDox('I3 with dismiss replaces the open question comment with the label dismissal and its questions without answers, and an empty body becomes "Dismissed."')]
    public function testUserDismissesQuestions(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $asked = $this->ask();

        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => ' ', 'label' => 'decision', 'replaces' => $asked, 'dismiss' => true]), 201, self::ISSUE);

        $questions = [['question' => 'Remove `round()` in 3.0?', 'detail' => 'BILL-9 still calls it.', 'answer' => null], ['question' => 'Which type holds the amounts?', 'detail' => null, 'answer' => null]];
        self::assertSame(
            [['dismissal', 'user', 'Dismissed.', $asked, $questions]],
            array_map(static fn (array $entry): array => [$entry['label'], $entry['actor']['kind'], $entry['body'], $entry['replaces'], $entry['questions']], $this->agent('get_issue', ['issue' => 'SM-1'])['thread']),
        );
    }

    #[TestDox('I3 refuses dismiss from a token, with questions, without replaces, and for a comment that is no open question comment, and refuses the label dismissal without dismiss')]
    public function testDismissRefusals(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->agent('comment_on_issue', ['issue' => 'SM-1', 'body' => 'The test is next.']);
        $plain = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'][0]['id'];
        $asked = $this->ask();
        $field = fn (Response $response): ?string => self::assertApiError($response, 422, 'invalid_input')['details']['violations'][0]['field'] ?? null;
        $post = fn (array $body): Response => self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', $body + ['body' => '', 'dismiss' => true]);

        self::assertSame(
            ['dismiss', 'dismiss', 'replaces', 'replaces', 'label'],
            [
                $field(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'replaces' => $asked, 'dismiss' => true, 'session' => self::SESSION])),
                $field($post(['replaces' => $asked, 'questions' => [['question' => 'Remove `round()` in 3.0?', 'answer' => 'Yes'], ['question' => 'Which type holds the amounts?', 'answer' => 'Money']]])),
                $field($post([])),
                $field($post(['replaces' => $plain])),
                $field($post(['body' => 'Not now.', 'label' => 'dismissal', 'dismiss' => false])),
            ],
        );
        self::assertSame([$plain, $asked], array_column($this->agent('get_issue', ['issue' => 'SM-1', 'history' => true])['thread'], 'id'), 'A refused dismissal is not stored.');
    }

    #[TestDox('I3 of a user with answers and waitingFor writes the answer, then a hand-over in the status of the issue')]
    public function testUserAnswersAndHandsOver(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $asked = $this->ask();
        $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'todo', 'waitingFor' => 'requester']);
        $answers = [['question' => 'Remove `round()` in 3.0?', 'answer' => 'Yes'], ['question' => 'Which type holds the amounts?', 'answer' => 'Money']];

        $handed = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'replaces' => $asked, 'questions' => $answers, 'waitingFor' => 'assignee']), 201, self::ISSUE);

        $thread = $this->agent('get_issue', ['issue' => 'SM-1'])['thread'];
        self::assertSame(['todo', 'assignee'], [$handed['status'], $handed['waitingFor']]);
        self::assertSame(
            [['comment', 'answer', 'user', null, null, null], ['status_changed', null, 'user', 'todo', 'todo', 'assignee']],
            array_map(static fn (array $entry): array => [$entry['kind'], $entry['label'], $entry['actor']['kind'], $entry['from'], $entry['to'], $entry['waitingFor']], \array_slice($thread, -2)),
        );
    }

    #[TestDox('I3 refuses waitingFor from a token on an issue in todo and from a user in backlog, and stores no comment')]
    public function testHandOverRefusals(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'backlog']);
        $body = ['body' => 'Drop step 3.', 'label' => 'review', 'waitingFor' => 'assignee'];
        $violation = static fn (Response $response): array => self::assertApiError($response, 422, 'invalid_input')['details']['violations'][0] ?? [];
        $kinds = fn (string $issueKey): array => array_column($this->agent('get_issue', ['issue' => $issueKey, 'history' => true])['thread'], 'kind');

        $byToken = $violation(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/issues/SM-1/comments', $body + ['session' => self::SESSION]));
        $inBacklog = $violation(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-2/comments', $body));

        self::assertSame(['waitingFor', 'Only a signed-in user hands over with a comment. Call set_issue_status.'], [$byToken['field'] ?? null, $byToken['message'] ?? null]);
        self::assertSame('waitingFor', $inBacklog['field'] ?? null);
        self::assertSame([['section_changed'], ['section_changed']], [$kinds('SM-1'), $kinds('SM-2')], 'A refused hand-over stores no comment.');
    }

    #[TestDox('I8 to wont_do dismisses each open question comment with the user as the actor and leaves an answered one, and set_issue_status of an agent in a self project dismisses with the agent')]
    public function testWontDoDismissesOpenQuestions(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $answered = $this->ask();
        $first = $this->ask();
        $second = $this->ask();
        $answers = [['question' => 'Remove `round()` in 3.0?', 'answer' => 'Yes'], ['question' => 'Which type holds the amounts?', 'answer' => 'Money']];
        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'replaces' => $answered, 'questions' => $answers]), 201, self::ISSUE);
        $this->agent('comment_on_issue', ['issue' => 'SM-2', 'body' => 'One choice.', 'questions' => [['question' => 'Show the mode on the PDF?']]]);
        $askedOnSecond = array_last($this->agent('get_issue', ['issue' => 'SM-2'])['thread'])['id'];

        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/status', ['status' => 'wont_do', 'note' => 'SM-9 covers it.']), 200, self::ISSUE);
        $this->agent('set_issue_status', ['issue' => 'SM-2', 'status' => 'wont_do', 'note' => 'SM-9 covers it.']);

        $dismissed = "Dismissed: the issue is won't do.";
        $rows = fn (string $issue): array => array_map(static fn (array $entry): array => [$entry['label'] ?? $entry['kind'], $entry['actor']['kind'], $entry['body'], $entry['replaces']], $this->agent('get_issue', ['issue' => $issue])['thread']);
        self::assertSame([['answer', 'user', '', $answered], ['status_changed', 'user', null, null], ['dismissal', 'user', $dismissed, $first], ['dismissal', 'user', $dismissed, $second]], $rows('SM-1'));
        self::assertSame([['status_changed', 'agent', null, null], ['dismissal', 'agent', $dismissed, $askedOnSecond]], $rows('SM-2'));
    }

    #[TestDox('I3 refuses an unknown label with 422 on label, and writes no comment')]
    public function testUnknownLabelIsRefused(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);

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

    #[TestDox('I4 hands an issue that waits for its requester to the assignee: in todo it keeps the status, a draft with a problem moves to todo, and a draft without one stays')]
    public function testApprovalHandsAWaitingIssueToTheAssignee(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::User);
        $inTodo = $this->plannedIssue('todo');
        $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'todo', 'waitingFor' => 'requester']);
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change', 'problem' => self::PROBLEM]);
        $inDraft = (string) $this->agent('update_issue', ['issue' => 'SM-2', 'plan' => self::PLAN])['planRevision'];
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round the quotes', 'category' => 'change']);
        $withoutProblem = (string) $this->agent('update_issue', ['issue' => 'SM-3', 'plan' => self::PLAN])['planRevision'];

        $approved = array_map(fn (array $approval): array => self::assertApiResponse($this->approve(...$approval), 200, self::ISSUE), [['SM-1', $inTodo], ['SM-2', $inDraft], ['SM-3', $withoutProblem]]);

        $entry = array_last($this->agent('get_issue', ['issue' => 'SM-1'])['thread']);
        self::assertSame([['todo', 'assignee'], ['todo', 'assignee'], ['draft', 'assignee']], array_map(static fn (array $issue): array => [$issue['status'], $issue['waitingFor']], $approved));
        self::assertSame(['status_changed', 'user', 'todo', 'todo', 'assignee'], [$entry['kind'] ?? null, $entry['actor']['kind'] ?? null, $entry['from'] ?? null, $entry['to'] ?? null, $entry['waitingFor'] ?? null]);
        self::assertSame('user', $approved[2]['planApproval']['approvedBy']['kind'] ?? null);
    }

    #[TestDox('I8 of a user sets approved, the issue waits for the assignee, and the agent then sets done in the mode user')]
    public function testUserApprovesTheWorkAndTheAgentEndsIt(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::User);
        self::assertApiResponse($this->approve('SM-1', $this->plannedIssue('todo')), 200, self::ISSUE);
        $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'in_progress']);
        $this->agent('update_issue', ['issue' => 'SM-1', 'result' => 'Branch sm-1 with a1b2c3d.']);
        $inReview = $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'in_review']);

        $approved = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/status', ['status' => 'approved']), 200, self::ISSUE);
        $done = $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'done']);

        self::assertSame(['requester', ['draft', 'backlog', 'todo', 'in_progress']], [$inReview['waitingFor'], $inReview['allowedStatuses']], 'Neither approved nor done for the agent.');
        self::assertSame(['approved', 'assignee'], [$approved['status'], $approved['waitingFor']]);
        self::assertSame(['done', null], [$done['status'], $done['waitingFor']]);
    }

    #[TestDox('I8 of a user hands the issue over in its status with an entry, and the party that it has writes none')]
    public function testUserHandsTheIssueOver(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->plannedIssue('todo');
        $handOver = fn (): Response => self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/status', ['status' => 'todo', 'waitingFor' => 'requester']);

        $handed = self::assertApiResponse($handOver(), 200, self::ISSUE);
        self::assertApiResponse($handOver(), 200, self::ISSUE);

        $moves = array_values(array_filter($this->agent('get_issue', ['issue' => 'SM-1'])['thread'], static fn (array $entry): bool => 'status_changed' === $entry['kind']));
        self::assertSame(['todo', 'requester'], [$handed['status'], $handed['waitingFor']]);
        self::assertSame([['user', 'todo', 'todo', 'requester']], array_map(static fn (array $entry): array => [$entry['actor']['kind'], $entry['from'], $entry['to'], $entry['waitingFor']], $moves));
    }

    #[TestDox('I6 and I7 take the requester and the assignee, with the signed-in user as user, and write no entry for them')]
    public function testUserSetsTheParties(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $user = ['kind' => 'user', 'userId' => $this->user->getId()->toString(), 'name' => 'jan'];

        $created = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/projects/SM/issues', ['title' => 'Round invoice totals once', 'category' => 'change', 'assignee' => 'user']), 201, self::ISSUE);
        $updated = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', '/api/v1/issues/SM-1', ['requester' => 'agent', 'assignee' => 'agent']), 200, self::ISSUE);

        self::assertSame([$user, $user, 'requester'], [$created['requester'], $created['assignee'], $created['waitingFor']]);
        self::assertSame(['agent', 'agent', 'requester'], [$updated['requester']['kind'], $updated['assignee']['kind'], $updated['waitingFor']]);
        self::assertSame([], $this->agent('get_issue', ['issue' => 'SM-1'])['thread']);
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
        $start = ['issue' => 'SM-1', 'session' => self::SESSION, 'status' => 'in_progress'];

        $before = self::assertToolError($this->mcp->callTool('set_issue_status', $start), 'invalid_input');
        self::assertApiResponse($this->approve('SM-1', $planRevision), 200, self::ISSUE);
        $after = $this->agent('set_issue_status', $start);

        self::assertSame('plan', $before['details']['violations'][0]['field'] ?? null);
        self::assertSame('in_progress', $after['status']);
    }

    #[TestDox('In a by_issue project, I7 of a user changes the approval from user to self, so an agent starts without an approval, and refuses by_issue')]
    public function testUserChangesTheApproval(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::ByIssue);
        $this->plannedIssue('todo');
        $start = ['issue' => 'SM-1', 'session' => self::SESSION, 'status' => 'in_progress'];
        $approval = fn (string $approval): Response => self::apiRequest($this->client, 'PATCH', '/api/v1/issues/SM-1', ['approval' => $approval]);

        $before = self::assertToolError($this->mcp->callTool('set_issue_status', $start), 'invalid_input');
        $lowered = self::assertApiResponse($approval('self'), 200, self::ISSUE);
        $started = $this->agent('set_issue_status', $start);
        $byIssue = self::assertApiError($approval('by_issue'), 422, 'invalid_input');

        self::assertSame(['plan', 'self', 'in_progress'], [$before['details']['violations'][0]['field'] ?? null, $lowered['approval'], $started['status']]);
        self::assertSame(['approval'], array_column($byIssue['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('I6 without category answers 422 on category, and I7 of a user changes the category of an ended issue')]
    public function testCategoryOfTheUser(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'wont_do', 'note' => 'SM-9 covers it.']);

        $missing = self::assertApiError(self::apiRequest($this->client, 'POST', '/api/v1/projects/SM/issues', ['title' => 'Show the rounding mode']), 422, 'invalid_input');
        $changed = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', '/api/v1/issues/SM-1', ['category' => 'bug']), 200, self::ISSUE);

        self::assertSame(['category'], array_column($missing['details']['violations'] ?? [], 'field'));
        self::assertSame(['bug', 'wont_do'], [$changed['category'], $changed['status']]);
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

    #[TestDox('I9 holds a blocked issue, its blockers of both projects, an issue of another project that waits, and each issue that waits for a user; it leaves out a draft and an issue that waits for an agent without a blocked_by link, and a link after one of its issues ends')]
    public function testDependencyGraph(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        self::storeProject($this->user->getTenantId(), 'DOC');
        $this->plannedIssue('todo');
        $this->agent('update_issue', ['issue' => 'SM-1', 'result' => 'Added `TaxRounding::perInvoice()` in a1b2c3d.']);
        $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => 'in_review']);

        foreach (['SM' => ['Add the rounding mode', 'Show the rounding mode', 'Round the shipping totals', 'Round the quotes'], 'DOC' => ['Name the rounding modes', 'Name the tax terms']] as $project => $titles) {
            foreach ($titles as $title) {
                $this->agent('create_issue', ['project' => $project, 'title' => $title, 'category' => 'change']);
            }
        }

        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round the credit notes', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);

        $this->agent('link_issues', ['issue' => 'SM-3', 'target' => 'SM-2', 'note' => 'SM-2 adds the mode.', 'kind' => 'blocked_by']);
        $this->agent('link_issues', ['issue' => 'SM-3', 'target' => 'DOC-1', 'note' => 'DOC-1 names the modes.', 'kind' => 'blocked_by']);
        $this->agent('link_issues', ['issue' => 'SM-4', 'target' => 'SM-2', 'note' => 'The same rounding.']);
        $this->agent('update_issue', ['issue' => 'SM-5', 'problem' => self::PROBLEM]);
        $this->agent('set_issue_status', ['issue' => 'SM-5', 'status' => 'todo', 'waitingFor' => 'requester']);
        $this->agent('link_issues', ['issue' => 'DOC-2', 'target' => 'SM-5', 'note' => 'SM-5 names the terms.', 'kind' => 'blocked_by']);
        $graph = fn (): array => self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/projects/SM/graph'), 200, 'issue.schema.json#/$defs/IssueGraph');

        $open = $graph();
        $this->agent('set_issue_status', ['issue' => 'SM-2', 'status' => 'wont_do', 'note' => 'The mode exists.']);
        $this->agent('set_issue_status', ['issue' => 'DOC-1', 'status' => 'wont_do', 'note' => 'The handbook has them.']);
        $this->agent('set_issue_status', ['issue' => 'DOC-2', 'status' => 'wont_do', 'note' => 'The glossary has them.']);
        $ended = $graph();

        self::assertSame([['DOC-1', 'draft'], ['DOC-2', 'draft'], ['SM-1', 'in_review'], ['SM-2', 'draft'], ['SM-3', 'draft'], ['SM-5', 'todo']], array_map(static fn (array $issue): array => [$issue['key'], $issue['status']], $open['issues']));
        self::assertSame([['issue' => 'DOC-2', 'blockedBy' => 'SM-5'], ['issue' => 'SM-3', 'blockedBy' => 'DOC-1'], ['issue' => 'SM-3', 'blockedBy' => 'SM-2']], $open['links']);
        self::assertSame(2, $open['otherOpenIssues'], 'SM-4, a draft that waits for the user with a relates link only, and SM-6, which waits for the agent.');
        self::assertSame([['SM-1', 'SM-5'], [], 3], [array_column($ended['issues'], 'key'), $ended['links'], $ended['otherOpenIssues']]);
    }

    #[TestDox('A key that the caller cannot see answers 404 before the 403 and the body error, and a malformed key answers 404')]
    public function testHiddenKeyIsNotFoundFirst(): void
    {
        $other = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        self::storeProject($other->getTenantId(), 'DOC');
        $otherMcp = new McpTestClient($this->client, self::storeToken($other)[1]);
        self::assertToolResult($otherMcp, $otherMcp->callTool('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding', 'category' => 'change']), 'create_issue');
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);

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
        $this->agent('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $planRevision = (string) $this->agent('update_issue', ['issue' => 'SM-1', 'plan' => self::PLAN])['planRevision'];

        if ('todo' !== $status) {
            $this->agent('set_issue_status', ['issue' => 'SM-1', 'status' => $status]);
        }

        return $planRevision;
    }

    /**
     * @return string The ID of the comment of the agent on SM-1 that asks two questions
     */
    private function ask(): string
    {
        $this->agent('comment_on_issue', ['issue' => 'SM-1', 'body' => 'Two choices before the plan.', 'questions' => [['question' => 'Remove `round()` in 3.0?', 'detail' => 'BILL-9 still calls it.'], ['question' => 'Which type holds the amounts?']]]);

        return array_last($this->agent('get_issue', ['issue' => 'SM-1'])['thread'])['id'];
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
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, self::withSession($tool, $arguments)), $tool);
    }

    /**
     * The four writes that need session (D130) get the one session of this test.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function withSession(string $tool, array $arguments): array
    {
        return \in_array($tool, ['update_issue', 'set_issue_status', 'approve_plan', 'comment_on_issue'], true) ? $arguments + ['session' => self::SESSION] : $arguments;
    }
}
