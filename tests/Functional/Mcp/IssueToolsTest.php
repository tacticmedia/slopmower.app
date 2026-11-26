<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Domain\Actor\Actor;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\IssueId;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueKey;
use App\Entity\Issue;
use App\Entity\IssueEntry;
use App\Entity\User;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * docs/specs/mcp.md sections 4.19 to 4.28, and the visibility of 4.29. The agent of a token for all repositories works
 * on the issues of the project SM.
 */
final class IssueToolsTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use McpAssertions;
    use ResetDatabase;

    private const string PROBLEM = 'Invoice totals round each line. Done when a test with three lines of 0.335 gives 1.01.';
    private const string ACCEPTANCE_CRITERIA = 'An invoice of three lines of 0.335 totals 1.01, not 1.02.';
    private const string PLAN = '1. Add `TaxRounding::perInvoice()`. 2. Test three lines of 0.335.';
    private const string RESULT = 'Added `TaxRounding::perInvoice()` in a1b2c3d. No deviation.';
    private const string REVIEW_NOTE = 'The plan covers the rounding and its test.';
    private const string REVIEWER = 'plan-reviewer';
    private const string AGENT_NAME = 'claude-code laptop';
    private const string GUIDELINES = "- A change of an API: `user`.\n- A fix of a typo: `self`.";
    private const string SESSION = 'billing-3f9a';
    private const array AGENT_PARTY = ['kind' => 'agent', 'userId' => null, 'name' => null];

    private KernelBrowser $client;

    private User $user;

    private McpTestClient $mcp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = TenantFixtures::of(self::getContainer())->create();
        $this->mcp = new McpTestClient($this->client, self::storeToken($this->user)[1]);
    }

    #[TestDox('create_issue, list_issues, and get_issue return the issue, and the numbers count per project')]
    public function testCreateListAndGet(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        self::storeProject($this->user->getTenantId(), 'DOC');

        $first = $this->call('create_issue', ['project' => 'SM', 'title' => '  Round invoice totals once  ', 'category' => 'change']);
        $second = $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $other = $this->call('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding', 'category' => 'change']);
        $page = $this->call('list_issues', ['project' => 'SM']);
        $todo = $this->call('list_issues', ['project' => 'SM', 'status' => 'todo']);
        $detail = $this->call('get_issue', ['issue' => 'SM-2']);

        self::assertSame(['SM-1', 'SM-2', 'DOC-1'], [$first['key'], $second['key'], $other['key']]);
        self::assertSame(['Round invoice totals once', 'draft', 'self', null, null, 'agent', self::AGENT_NAME], [$first['title'], $first['status'], $first['approval'], $first['problem'], $first['planRevision'], $first['createdBy']['kind'], $first['createdBy']['agentName']]);
        self::assertSame(['key' => 'SM', 'name' => 'Project SM', 'approvalMode' => 'self'], $first['project']);
        self::assertSame(['SM-1', 'SM-2'], array_column($page['items'], 'key'), 'In rank order.');
        self::assertSame(['key' => 'SM-2', 'title' => 'Show the rounding mode', 'category' => 'change', 'status' => 'todo', 'approval' => 'self', 'claim' => null, 'requester' => $this->userParty(), 'assignee' => self::AGENT_PARTY, 'waitingFor' => 'assignee', 'updatedAt' => $second['updatedAt'], 'lastActivityAt' => null], $todo['items'][0]);
        self::assertSame([$this->userParty(), self::AGENT_PARTY, 'requester'], [$first['requester'], $first['assignee'], $first['waitingFor']], 'The user of the token requests, an agent works, and a draft waits for the requester.');
        self::assertCount(1, $todo['items']);
        self::assertSame($second, $detail['issue']);
        self::assertSame([], $detail['links']);
        self::assertSame([['section_changed', 'problem', null]], array_map(static fn (array $entry): array => [$entry['kind'], $entry['section'], $entry['body']], $detail['thread']), 'A section_changed entry has its section and no text.');
        self::assertSame('problem', $this->refusal('create_issue', ['project' => 'SM', 'title' => 'Without a problem', 'category' => 'change', 'status' => 'todo'])['field']);
        self::assertSame(['SM-3'], [$this->call('create_issue', ['project' => 'SM', 'title' => 'After a refusal', 'category' => 'change'])['key']], 'A refused issue takes no number.');
    }

    #[TestDox('create_issue needs category, and a bug shows it in the issue, the list, a link, and a comment row')]
    public function testCategoryOfANewIssue(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');

        self::assertJsonRpcError($this->mcp->callToolResponse('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']), -32602);
        $bug = $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'bug']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $this->call('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => 'SM-1 rounds the totals.']);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'The test is next.']);

        self::assertSame(['SM-1', 'bug'], [$bug['key'], $bug['category']]);
        self::assertEquals(['SM-1' => 'bug', 'SM-2' => 'change'], array_column($this->call('list_issues', ['project' => 'SM'])['items'], 'category', 'key'));
        self::assertSame('bug', $this->call('get_issue', ['issue' => 'SM-2'])['links'][0]['issue']['category']);
        self::assertSame('bug', $this->call('list_comments', ['project' => 'SM'])['items'][0]['issue']['category']);
    }

    #[TestDox('update_issue changes the category without a new updatedAt and without an entry, and an agent cannot change it on an ended issue')]
    public function testCategoryChange(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        self::mockTime('2026-11-25 09:00:00');
        $created = $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM]);
        self::mockTime('2026-11-25 10:00:00');

        $changed = $this->call('update_issue', ['issue' => 'SM-1', 'category' => 'bug']);
        $thread = $this->call('get_issue', ['issue' => 'SM-1', 'history' => true])['thread'];
        $this->call('set_issue_status', ['issue' => 'SM-1', 'status' => 'wont_do', 'note' => 'SM-9 covers it.']);
        $ended = $this->refusal('update_issue', ['issue' => 'SM-1', 'category' => 'change']);

        self::assertSame(['bug', $created['updatedAt']], [$changed['category'], $changed['updatedAt']]);
        self::assertSame([['section_changed', 'problem']], array_map(static fn (array $entry): array => [$entry['kind'], $entry['section']], $thread));
        self::assertSame(['issue', 'bug'], [$ended['field'], $this->call('get_issue', ['issue' => 'SM-1'])['issue']['category']]);
    }

    #[TestDox('list_issues filters by category, and refuses a cursor of another category')]
    public function testListByCategory(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'bug']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round the shipping totals twice', 'category' => 'bug']);

        $bugs = $this->call('list_issues', ['project' => 'SM', 'category' => 'bug']);
        $firstPage = $this->call('list_issues', ['project' => 'SM', 'category' => 'bug', 'limit' => 1]);
        $otherCategory = $this->refusal('list_issues', ['project' => 'SM', 'category' => 'change', 'limit' => 1, 'cursor' => $firstPage['nextCursor']]);

        self::assertSame(['SM-1', 'SM-3'], array_column($bugs['items'], 'key'));
        self::assertSame('cursor', $otherCategory['field']);
    }

    #[TestDox('set_issue_status gives each status its default party, a hand-over is an entry from a status to itself, and backlog takes no waitingFor')]
    public function testWaitingForOfEachStatusAndAHandOver(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM]);
        $this->call('update_issue', ['issue' => 'SM-1', 'plan' => self::PLAN, 'result' => self::RESULT]);
        $issue = ['issue' => 'SM-1'];

        $waiting = array_map(fn (string $status): array => [$status, $this->call('set_issue_status', $issue + ['status' => $status])['waitingFor']], ['backlog', 'todo', 'in_progress', 'in_review', 'approved', 'draft']);
        $refused = $this->refusal('set_issue_status', $issue + ['status' => 'backlog', 'waitingFor' => 'requester']);
        $handedOver = $this->call('set_issue_status', $issue + ['status' => 'draft', 'waitingFor' => 'assignee']);
        $entry = array_last($this->call('get_issue', $issue)['thread']);

        self::assertSame([['backlog', null], ['todo', 'assignee'], ['in_progress', 'assignee'], ['in_review', 'requester'], ['approved', 'assignee'], ['draft', 'requester']], $waiting);
        self::assertSame('waitingFor', $refused['field']);
        self::assertSame(['draft', 'assignee'], [$handedOver['status'], $handedOver['waitingFor']]);
        self::assertSame(['status_changed', 'draft', 'draft', 'assignee'], [$entry['kind'] ?? null, $entry['from'] ?? null, $entry['to'] ?? null, $entry['waitingFor'] ?? null]);
        self::assertSame('status', $this->refusal('set_issue_status', $issue + ['status' => 'draft', 'waitingFor' => 'assignee'])['field'], 'The party that the issue has.');
    }

    #[TestDox('update_issue changes the assignee and the waiting party follows, and list_issues filters by waitingForKind with its own cursor')]
    public function testAssigneeAndTheWaitingFilter(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        foreach (['Round invoice totals once', 'Show the rounding mode', 'Round the shipping totals twice'] as $title) {
            $this->call('create_issue', ['project' => 'SM', 'title' => $title, 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo']);
        }

        $reassigned = $this->call('update_issue', ['issue' => 'SM-2', 'assignee' => 'user']);
        $keys = fn (string $kind): array => array_column($this->call('list_issues', ['project' => 'SM', 'waitingForKind' => $kind])['items'], 'key');
        $firstPage = $this->call('list_issues', ['project' => 'SM', 'waitingForKind' => 'agent', 'limit' => 1]);
        $otherKind = $this->refusal('list_issues', ['project' => 'SM', 'waitingForKind' => 'user', 'limit' => 1, 'cursor' => $firstPage['nextCursor']]);

        self::assertSame([$this->userParty(), 'assignee'], [$reassigned['assignee'], $reassigned['waitingFor']]);
        self::assertSame([['SM-2'], ['SM-1', 'SM-3']], [$keys('user'), $keys('agent')]);
        self::assertSame('cursor', $otherKind['field']);
    }

    #[TestDox('The agent as the requester is refused with the approval user, and passes on a by_issue issue with self')]
    public function testAgentAsTheRequester(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::ByIssue);
        $request = ['project' => 'SM', 'title' => 'Bump the patch versions', 'category' => 'change', 'requester' => 'agent'];

        $refused = $this->refusal('create_issue', $request);
        $created = $this->call('create_issue', $request + ['approval' => 'self']);

        self::assertSame('requester', $refused['field']);
        self::assertSame([self::AGENT_PARTY, 'requester'], [$created['requester'], $created['waitingFor']]);
    }

    #[TestDox('An issue stored without a category, as a data dump from before the column restores it, reads as change')]
    public function testIssueWithoutACategoryReadsAsChange(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'bug']);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $columns = implode(', ', $connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'issue' AND column_name NOT IN ('id', 'number', 'category')"));

        $connection->executeStatement("INSERT INTO issue (id, number, {$columns}) SELECT ?, 2, {$columns} FROM issue", [IssueId::generate()->toRfc4122()]);

        self::assertSame('change', $this->call('get_issue', ['issue' => 'SM-2'])['issue']['category']);
    }

    #[TestDox('Each row of the rules table refuses and allows, as the approval that binds the issue says')]
    #[DataProvider('approvalModes')]
    public function testRulesOfEachApprovalMode(ApprovalMode $projectMode, ?ApprovalMode $approval): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', $projectMode);
        $mode = $approval ?? (ApprovalMode::ByIssue === $projectMode ? ApprovalMode::User : $projectMode);
        $create = fn (string $title): array => $this->call('create_issue', ['project' => 'SM', 'title' => $title, 'category' => 'change'] + (null === $approval ? [] : ['approval' => $approval->value]));
        $created = $create('Round invoice totals once');
        $issue = ['issue' => $created['key']];
        $review = ['note' => 'The test passes.', 'reviewer' => 'result-reviewer'];
        self::assertSame($mode->value, $created['approval']);

        // backlog and todo need a problem.
        self::assertSame('problem', $this->refusal('set_issue_status', $issue + ['status' => 'backlog'])['field']);
        $this->call('update_issue', $issue + ['problem' => self::PROBLEM]);
        self::assertSame('backlog', $this->call('set_issue_status', $issue + ['status' => 'backlog'])['status']);
        self::assertSame('todo', $this->call('set_issue_status', $issue + ['status' => 'todo'])['status']);

        // in_progress needs a plan, approved as the mode says.
        self::assertSame('plan', $this->refusal('set_issue_status', $issue + ['status' => 'in_progress'])['field']);
        $planRevision = $this->call('update_issue', $issue + ['plan' => self::PLAN])['planRevision'];
        self::assertIsString($planRevision);
        $approval = $issue + ['planRevision' => $planRevision, 'reviewer' => self::REVIEWER, 'reviewNote' => self::REVIEW_NOTE];

        if (ApprovalMode::Self !== $mode) {
            self::assertSame('plan', $this->refusal('set_issue_status', $issue + ['status' => 'in_progress'])['field'], 'The plan has no approval.');
        }

        if (ApprovalMode::AgentReview === $mode) {
            $this->call('approve_plan', $approval);
        } elseif (ApprovalMode::User === $mode) {
            self::assertSame('issue', $this->refusal('approve_plan', $approval)['field']);
            $this->storeUserApproval($issue['issue'], $planRevision);
        }

        self::assertSame('in_progress', $this->call('set_issue_status', $issue + ['status' => 'in_progress'])['status']);

        // in_review needs a result.
        self::assertSame('result', $this->refusal('set_issue_status', $issue + ['status' => 'in_review'])['field']);
        $this->call('update_issue', $issue + ['result' => self::RESULT]);
        self::assertSame('in_review', $this->call('set_issue_status', $issue + ['status' => 'in_review'])['status']);

        // wont_do needs a note, and an agent ends an issue as the mode says.
        $duplicate = ['issue' => $create('A duplicate')['key'], 'status' => 'wont_do'];

        if (ApprovalMode::User === $mode) {
            self::assertSame('status', $this->refusal('set_issue_status', $issue + ['status' => 'done'] + $review)['field']);
            self::assertSame('status', $this->refusal('set_issue_status', $duplicate + $review)['field']);
            self::assertSame(['draft', 'backlog', 'todo', 'in_progress'], $this->call('get_issue', $issue)['issue']['allowedStatuses']);

            return;
        }

        if (ApprovalMode::AgentReview === $mode) {
            self::assertSame('reviewer', $this->refusal('set_issue_status', $issue + ['status' => 'done', 'note' => $review['note']])['field']);
            self::assertSame('note', $this->refusal('set_issue_status', $issue + ['status' => 'done', 'reviewer' => $review['reviewer']])['field']);
        }

        self::assertSame('note', $this->refusal('set_issue_status', $duplicate + ['reviewer' => $review['reviewer']])['field']);
        self::assertSame('wont_do', $this->call('set_issue_status', $duplicate + $review)['status']);
        $done = $this->call('set_issue_status', $issue + ['status' => 'done'] + (ApprovalMode::AgentReview === $mode ? $review : []));
        self::assertSame(['done', []], [$done['status'], $done['allowedStatuses']]);
    }

    /**
     * @return iterable<string, array{ApprovalMode, ApprovalMode|null}> The mode of the project, and the approval of the issue
     */
    public static function approvalModes(): iterable
    {
        foreach ([ApprovalMode::User, ApprovalMode::AgentReview, ApprovalMode::Self] as $mode) {
            yield $mode->value => [$mode, null];
        }

        yield 'by_issue without approval' => [ApprovalMode::ByIssue, null];
        yield 'by_issue with self' => [ApprovalMode::ByIssue, ApprovalMode::Self];
    }

    #[TestDox('list_projects returns the approval guidelines that the user saved')]
    public function testListProjectsReturnsTheGuidelines(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->client->loginUser($this->user, 'web');
        self::apiRequest($this->client, 'PATCH', '/api/v1/projects/SM', ['approvalMode' => 'by_issue', 'approvalGuidelines' => self::GUIDELINES]);

        self::assertSame([['by_issue', self::GUIDELINES]], array_map(static fn (array $project): array => [$project['approvalMode'], $project['approvalGuidelines']], $this->call('list_projects', [])['items']));
    }

    #[TestDox('An agent sets any approval on a new issue of a by_issue project, later changes it only to user, and sets none in a project of another mode')]
    public function testApprovalOfAnAgent(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::ByIssue);
        self::storeProject($this->user->getTenantId(), 'DOC', ApprovalMode::User);
        $logged = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Fix a typo in the README', 'category' => 'change', 'approval' => 'self'])['key']];
        $material = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];

        $lowered = $this->refusal('update_issue', $material + ['approval' => 'self']);
        $raised = $this->call('update_issue', $logged + ['approval' => 'user']);
        $loweredAgain = $this->refusal('update_issue', $logged + ['approval' => 'self']);
        $otherMode = $this->refusal('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding', 'category' => 'change', 'approval' => 'user']);

        self::assertSame(['approval', 'approval', 'approval'], [$lowered['field'], $loweredAgain['field'], $otherMode['field']]);
        self::assertSame(['user', 'user'], [$raised['approval'], $this->call('get_issue', $material)['issue']['approval']]);
        self::assertSame([], $this->call('list_issues', ['project' => 'DOC'])['items'], 'A refused issue is not stored.');
    }

    #[TestDox('A plan with new text clears the approval, and the same text keeps it')]
    public function testNewPlanTextClearsTheApproval(): void
    {
        // One instant, so the renewed claim of each write is the same.
        self::mockTime('2026-11-25 09:00:00');
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo'])['key']];
        $planned = $this->call('update_issue', $issue + ['plan' => self::PLAN]);

        $approved = $this->call('approve_plan', $issue + ['planRevision' => $planned['planRevision'], 'reviewer' => self::REVIEWER, 'reviewNote' => self::REVIEW_NOTE]);
        $sameText = $this->call('update_issue', $issue + ['plan' => self::PLAN]);
        $entriesAfterSameText = count($this->call('get_issue', $issue)['thread']);
        $newText = $this->call('update_issue', $issue + ['plan' => self::PLAN.' 3. Update the README.']);

        self::assertNull($planned['planApproval']);
        self::assertSame(['agent', self::AGENT_NAME], [$approved['planApproval']['approvedBy']['kind'], $approved['planApproval']['approvedBy']['agentName']]);
        self::assertSame([self::REVIEWER, self::REVIEW_NOTE], [$approved['planApproval']['reviewer'], $approved['planApproval']['reviewNote']]);
        self::assertContains('in_progress', $approved['allowedStatuses']);
        self::assertSame($approved, $sameText, 'The same text changes nothing.');
        self::assertSame(3, $entriesAfterSameText, 'The problem, the plan, and the approval: the same text writes no entry.');
        self::assertNull($newText['planApproval']);
        self::assertNotSame($planned['planRevision'], $newText['planRevision']);
        self::assertNotContains('in_progress', $newText['allowedStatuses']);
    }

    #[TestDox('approve_plan refuses an old planRevision, an empty plan, and the approval mode user')]
    public function testApprovePlanRefusals(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        self::storeProject($this->user->getTenantId(), 'DOC', ApprovalMode::User);
        $review = ['reviewer' => self::REVIEWER, 'reviewNote' => self::REVIEW_NOTE];
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];
        $userIssue = ['issue' => $this->call('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding', 'category' => 'change'])['key']];

        $withoutPlan = $this->refusal('approve_plan', $issue + ['planRevision' => IssueEntryId::generate()->toString()] + $review);
        $oldRevision = $this->call('update_issue', $issue + ['plan' => self::PLAN])['planRevision'];
        $newRevision = $this->call('update_issue', $issue + ['plan' => self::PLAN.' 3. Update the README.'])['planRevision'];
        $old = $this->refusal('approve_plan', $issue + ['planRevision' => $oldRevision] + $review);
        $shortNote = $this->refusal('approve_plan', $issue + ['planRevision' => $newRevision, 'reviewer' => self::REVIEWER, 'reviewNote' => str_pad('Fine.', 20)]);
        $newest = $this->call('approve_plan', $issue + ['planRevision' => $newRevision] + $review);
        $userMode = $this->refusal('approve_plan', $userIssue + ['planRevision' => $this->call('update_issue', $userIssue + ['plan' => self::PLAN])['planRevision']] + $review);

        self::assertSame(['plan', 'planRevision', 'reviewNote', 'issue'], [$withoutPlan['field'], $old['field'], $shortNote['field'], $userMode['field']]);
        self::assertSame(self::REVIEWER, $newest['planApproval']['reviewer']);
        self::assertNull($this->call('get_issue', $userIssue)['issue']['planApproval'], 'A refused approval is not stored.');
    }

    #[TestDox('An agent cannot change the plan of an issue in in_progress, and cannot change an issue in done')]
    public function testPlanOfWorkInProgressAndEndedIssue(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo'])['key']];
        $this->call('update_issue', $issue + ['plan' => self::PLAN]);
        $this->call('set_issue_status', $issue + ['status' => 'in_progress']);

        $planInProgress = $this->refusal('update_issue', $issue + ['plan' => 'Another plan.']);
        $withResult = $this->call('update_issue', $issue + ['plan' => self::PLAN, 'result' => self::RESULT]);
        $sameStatus = $this->refusal('set_issue_status', $issue + ['status' => 'in_progress']);
        $this->call('set_issue_status', $issue + ['status' => 'done']);
        $fieldOfDone = $this->refusal('update_issue', $issue + ['followUps' => 'Round the shipping totals.']);
        $statusOfDone = $this->refusal('set_issue_status', $issue + ['status' => 'todo']);
        $commented = $this->call('comment_on_issue', $issue + ['body' => 'Released in 2.4.']);

        self::assertSame(['plan', 'status', 'issue', 'issue'], [$planInProgress['field'], $sameStatus['field'], $fieldOfDone['field'], $statusOfDone['field']]);
        self::assertStringContainsString('result', $planInProgress['message']);
        self::assertSame([self::PLAN, self::RESULT], [$withResult['plan'], $withResult['result']], 'The same plan text is no change.');
        self::assertSame(['done', null], [$commented['status'], $commented['followUps']]);
    }

    #[TestDox('An agent writes notes of an issue in in_progress, and the plan approval stays')]
    public function testNotesKeepThePlanApproval(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo'])['key']];
        $planned = $this->call('update_issue', $issue + ['plan' => self::PLAN]);
        $approved = $this->call('approve_plan', $issue + ['planRevision' => $planned['planRevision'], 'reviewer' => self::REVIEWER, 'reviewNote' => self::REVIEW_NOTE]);
        $this->call('set_issue_status', $issue + ['status' => 'in_progress']);

        $this->call('update_issue', $issue + ['notes' => 'Rounding is in `InvoiceTotals::total()`.']);
        $read = $this->call('get_issue', $issue)['issue'];

        self::assertSame('Rounding is in `InvoiceTotals::total()`.', $read['notes']);
        self::assertSame([$approved['planRevision'], $approved['planApproval']], [$read['planRevision'], $read['planApproval']]);
    }

    #[TestDox('create_issue and update_issue write acceptanceCriteria, each version a section_changed entry')]
    public function testAcceptanceCriteriaOfCreateAndUpdate(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');

        $created = $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'bug', 'problem' => self::PROBLEM, 'acceptanceCriteria' => self::ACCEPTANCE_CRITERIA]);
        $updated = $this->call('update_issue', ['issue' => 'SM-1', 'acceptanceCriteria' => 'An invoice of three lines of 0.335 totals 1.01.']);
        $thread = $this->call('get_issue', ['issue' => 'SM-1', 'history' => true])['thread'];

        self::assertSame([self::ACCEPTANCE_CRITERIA, 'An invoice of three lines of 0.335 totals 1.01.'], [$created['acceptanceCriteria'], $updated['acceptanceCriteria']]);
        self::assertSame([['problem', self::PROBLEM], ['acceptanceCriteria', self::ACCEPTANCE_CRITERIA], ['acceptanceCriteria', 'An invoice of three lines of 0.335 totals 1.01.']], array_map(static fn (array $entry): array => [$entry['section'], $entry['body']], $thread));
    }

    #[TestDox('A new version of acceptanceCriteria keeps the plan approval')]
    public function testAcceptanceCriteriaKeepThePlanApproval(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'status' => 'todo'])['key']];
        $planned = $this->call('update_issue', $issue + ['plan' => self::PLAN]);
        $approved = $this->call('approve_plan', $issue + ['planRevision' => $planned['planRevision'], 'reviewer' => self::REVIEWER, 'reviewNote' => self::REVIEW_NOTE]);

        $changed = $this->call('update_issue', $issue + ['acceptanceCriteria' => self::ACCEPTANCE_CRITERIA]);

        self::assertSame([$approved['planRevision'], $approved['planApproval']], [$changed['planRevision'], $changed['planApproval']]);
    }

    #[TestDox('An agent cannot change acceptanceCriteria of an issue in in_progress, and a user can')]
    public function testAcceptanceCriteriaOfWorkInProgress(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM, 'acceptanceCriteria' => self::ACCEPTANCE_CRITERIA, 'status' => 'todo'])['key']];
        $this->call('update_issue', $issue + ['plan' => self::PLAN]);
        $this->call('set_issue_status', $issue + ['status' => 'in_progress']);
        $this->client->loginUser($this->user, 'web');

        $ofTheAgent = $this->refusal('update_issue', $issue + ['acceptanceCriteria' => 'An invoice totals 1.01.']);
        self::apiRequest($this->client, 'PATCH', '/api/v1/issues/SM-1', ['acceptanceCriteria' => 'An invoice totals 1.01.']);

        self::assertSame('acceptanceCriteria', $ofTheAgent['field']);
        self::assertResponseIsSuccessful();
        self::assertSame('An invoice totals 1.01.', $this->call('get_issue', $issue)['issue']['acceptanceCriteria']);
    }

    #[TestDox('A comment and a status change are in the thread with their actor')]
    public function testThreadHoldsCommentsAndStatusChanges(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];

        $blank = $this->refusal('comment_on_issue', $issue + ['body' => "  \n"]);
        $this->call('comment_on_issue', $issue + ['body' => 'A duplicate of the rounding request.']);
        $this->call('set_issue_status', $issue + ['status' => 'wont_do', 'note' => 'SM-9 covers it.']);
        $thread = $this->call('get_issue', $issue)['thread'];

        self::assertSame('body', $blank['field']);
        self::assertSame(
            [['comment', self::AGENT_NAME, 'A duplicate of the rounding request.', null, null, null], ['status_changed', self::AGENT_NAME, null, 'draft', 'wont_do', 'SM-9 covers it.']],
            array_map(static fn (array $entry): array => [$entry['kind'], $entry['actor']['agentName'], $entry['body'], $entry['from'], $entry['to'], $entry['note']], $thread),
        );
        self::assertSame($this->user->getId()->toString(), $thread[0]['actor']['userId']);
    }

    #[TestDox('comment_on_issue stores the label, by default comment, and get_issue shows it')]
    public function testCommentLabel(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];

        $this->call('comment_on_issue', $issue + ['body' => 'The test is next.']);
        $this->call('comment_on_issue', $issue + ['body' => 'Round once per invoice, not per line.', 'label' => 'decision']);
        $this->call('set_issue_status', $issue + ['status' => 'wont_do', 'note' => 'SM-9 covers it.']);

        self::assertSame(
            [['comment', 'comment'], ['comment', 'decision'], ['status_changed', null]],
            array_map(static fn (array $entry): array => [$entry['kind'], $entry['label']], $this->call('get_issue', $issue)['thread']),
        );
    }

    #[TestDox('list_comments returns the comments of one label across the issues of the project, newest first, and none of another project')]
    public function testListCommentsByLabel(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        self::storeProject($this->user->getTenantId(), 'DOC');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM]);
        $second = $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $this->call('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding', 'category' => 'change']);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'Round once per invoice.', 'label' => 'decision']);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'The test is next.']);
        $commented = $this->call('comment_on_issue', ['issue' => 'SM-2', 'body' => 'Keep `round()` as deprecated.', 'label' => 'decision']);
        $this->call('comment_on_issue', ['issue' => 'DOC-1', 'body' => 'Describe it in the README.', 'label' => 'decision']);
        $row = static fn (array $comment): array => [$comment['issue']['key'], $comment['label'], $comment['body']];

        $decisions = $this->call('list_comments', ['project' => 'SM', 'label' => 'decision']);
        $all = $this->call('list_comments', ['project' => 'SM']);
        $firstPage = $this->call('list_comments', ['project' => 'SM', 'limit' => 1]);
        $secondPage = $this->call('list_comments', ['project' => 'SM', 'limit' => 1, 'cursor' => $firstPage['nextCursor']]);

        self::assertSame([['SM-2', 'decision', 'Keep `round()` as deprecated.'], ['SM-1', 'decision', 'Round once per invoice.']], array_map($row, $decisions['items']));
        self::assertNull($decisions['nextCursor']);
        self::assertSame(['key' => 'SM-2', 'title' => $second['title'], 'category' => 'change', 'status' => 'draft', 'approval' => 'self', 'claim' => null, 'requester' => $this->userParty(), 'assignee' => self::AGENT_PARTY, 'waitingFor' => 'requester', 'updatedAt' => $commented['updatedAt'], 'lastActivityAt' => $commented['updatedAt']], $decisions['items'][0]['issue']);
        self::assertSame(self::AGENT_NAME, $decisions['items'][0]['actor']['agentName']);
        self::assertSame([['SM-2', 'decision'], ['SM-1', 'comment'], ['SM-1', 'decision']], array_map(static fn (array $comment): array => [$comment['issue']['key'], $comment['label']], $all['items']), 'No section_changed entry, and no comment of DOC.');
        self::assertSame(['Keep `round()` as deprecated.', 'The test is next.'], [$firstPage['items'][0]['body'], $secondPage['items'][0]['body']]);
    }

    #[TestDox('list_comments with issue returns the comments of that issue, also with a label, and refuses a key of another project, an unknown issue, and a cursor of another filter')]
    public function testListCommentsOfOneIssue(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'Round once per invoice.', 'label' => 'decision']);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => 'The test is next.']);
        $this->call('comment_on_issue', ['issue' => 'SM-2', 'body' => 'Keep `round()` as deprecated.', 'label' => 'decision']);
        $bodies = static fn (array $page): array => array_column($page['items'], 'body');
        $unfiltered = $this->call('list_comments', ['project' => 'SM', 'limit' => 1]);

        self::assertSame(['The test is next.', 'Round once per invoice.'], $bodies($this->call('list_comments', ['project' => 'SM', 'issue' => 'SM-1'])));
        self::assertSame(['Round once per invoice.'], $bodies($this->call('list_comments', ['project' => 'SM', 'issue' => 'SM-1', 'label' => 'decision'])));
        self::assertSame('issue', $this->refusal('list_comments', ['project' => 'SM', 'issue' => 'DOC-1'])['field']);
        self::assertSame('cursor', $this->refusal('list_comments', ['project' => 'SM', 'issue' => 'SM-1', 'cursor' => $unfiltered['nextCursor']])['field']);
        self::assertToolError($this->mcp->callTool('list_comments', ['project' => 'SM', 'issue' => 'SM-9']), 'not_found');
    }

    #[TestDox('A comment stored without a label reads as comment, in get_issue and in the label filter of list_comments')]
    public function testCommentWithoutALabelReadsAsComment(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];
        $this->call('comment_on_issue', $issue + ['body' => 'Round once per invoice.', 'label' => 'decision']);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE issue_entry SET label = NULL');

        self::assertSame('comment', $this->call('get_issue', $issue)['thread'][0]['label']);
        self::assertSame(['comment'], array_column($this->call('list_comments', ['project' => 'SM', 'label' => 'comment'])['items'], 'label'));
        self::assertSame([], $this->call('list_comments', ['project' => 'SM', 'label' => 'decision'])['items']);
    }

    #[TestDox('get_issue leaves out a replaced plan version and its approval, and history returns every entry with its text and replacedBy')]
    public function testReplacedPlanVersion(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM])['key']];
        $newPlan = self::PLAN.' 3. Update the README.';
        $first = $this->call('update_issue', $issue + ['plan' => self::PLAN])['planRevision'];
        $this->call('approve_plan', $issue + ['planRevision' => $first, 'reviewer' => self::REVIEWER, 'reviewNote' => self::REVIEW_NOTE]);
        $second = $this->call('update_issue', $issue + ['plan' => $newPlan])['planRevision'];
        $row = static fn (array $entry): array => [$entry['kind'], $entry['section'], $entry['body'], $entry['replaces'], $entry['replacedBy']];

        $current = $this->call('get_issue', $issue)['thread'];
        $history = $this->call('get_issue', $issue + ['history' => true])['thread'];

        self::assertSame([['section_changed', 'problem', null, null, null], ['section_changed', 'plan', null, $first, null]], array_map($row, $current));
        self::assertSame([
            ['section_changed', 'problem', self::PROBLEM, null, null],
            ['section_changed', 'plan', self::PLAN, null, $second],
            ['plan_approved', null, null, null, $second],
            ['section_changed', 'plan', $newPlan, $first, null],
        ], array_map($row, $history));
    }

    #[TestDox('A comment with replaces takes the replaced comment out of get_issue and list_comments, and history keeps it')]
    public function testReplacedComment(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];
        $this->call('comment_on_issue', $issue + ['body' => '`round()` goes.', 'label' => 'decision']);
        $old = $this->call('get_issue', $issue)['thread'][0]['id'];
        $row = static fn (array $entry): array => [$entry['body'], $entry['replaces'], $entry['replacedBy']];

        $this->call('comment_on_issue', $issue + ['body' => '`round()` stays.', 'label' => 'decision', 'replaces' => $old]);
        $current = $this->call('get_issue', $issue)['thread'];
        $history = $this->call('get_issue', $issue + ['history' => true])['thread'];

        self::assertSame([['`round()` stays.', $old, null]], array_map($row, $current));
        self::assertSame([['`round()` goes.', null, $current[0]['id']], ['`round()` stays.', $old, null]], array_map($row, $history));
        self::assertSame(['`round()` stays.'], array_column($this->call('list_comments', ['project' => 'SM'])['items'], 'body'));
    }

    #[TestDox('comment_on_issue refuses on replaces a section entry, a comment of another issue, a comment of a user, and a replaced comment')]
    public function testReplacesRefusals(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => self::PROBLEM]);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $this->call('comment_on_issue', ['issue' => 'SM-1', 'body' => '`round()` goes.']);
        $this->call('comment_on_issue', ['issue' => 'SM-2', 'body' => 'The PDF has no footer.']);
        $this->client->loginUser($this->user, 'web');
        self::assertSame(201, self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => 'Keep `round()` as deprecated.'])->getStatusCode());
        [$section, $comment, $ofUser] = array_column($this->call('get_issue', ['issue' => 'SM-1'])['thread'], 'id');
        $ofOtherIssue = $this->call('get_issue', ['issue' => 'SM-2'])['thread'][0]['id'];
        $replace = ['issue' => 'SM-1', 'body' => '`round()` stays.'];

        $refusals = array_map(fn (string $id): array => $this->refusal('comment_on_issue', $replace + ['replaces' => $id]), [$section, $ofOtherIssue, $ofUser]);
        $this->call('comment_on_issue', $replace + ['replaces' => $comment]);
        $refusals[] = $this->refusal('comment_on_issue', $replace + ['replaces' => $comment]);

        self::assertSame(['replaces', 'replaces', 'replaces', 'replaces'], array_column($refusals, 'field'));
        self::assertSame(
            ['Is no comment of this issue.', 'Is no comment of this issue.', 'An agent cannot replace a comment of a user.', 'This comment is replaced already. Replace the comment that replaced it.'],
            array_column($refusals, 'message'),
        );
        self::assertCount(4, $this->call('get_issue', ['issue' => 'SM-1', 'history' => true])['thread'], 'A refused comment is not stored.');
    }

    #[TestDox('comment_on_issue with questions stores them trimmed with the label question and changes no status, and refuses the labels question, answer, and dismissal without questions')]
    public function testAgentAsksQuestions(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];
        $questions = [['question' => '  Remove `round()` in 3.0?  ', 'detail' => 'BILL-9 still calls it.'], ['question' => 'Which type holds the amounts?']];

        $asked = $this->call('comment_on_issue', $issue + ['body' => 'Two choices before the plan.', 'label' => 'decision', 'questions' => $questions]);
        $withoutQuestions = array_map(fn (string $label): array => $this->refusal('comment_on_issue', $issue + ['body' => 'Remove `round()` in 3.0?', 'label' => $label]), ['question', 'answer', 'dismissal']);

        self::assertSame('draft', $asked['status']);
        self::assertSame(
            [['question', [['question' => 'Remove `round()` in 3.0?', 'detail' => 'BILL-9 still calls it.', 'answer' => null], ['question' => 'Which type holds the amounts?', 'detail' => null, 'answer' => null]]]],
            array_map(static fn (array $entry): array => [$entry['label'], $entry['questions']], $this->call('get_issue', $issue)['thread']),
        );
        self::assertSame(['label', 'label', 'label'], array_column($withoutQuestions, 'field'));
    }

    #[TestDox('An agent replaces its open question comment, and list_comments with the label question then leaves it out')]
    public function testAgentWithdrawsItsQuestions(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];
        $this->call('comment_on_issue', $issue + ['body' => 'One choice before the plan.', 'questions' => [['question' => 'Remove `round()` in 3.0?']]]);

        $open = $this->call('list_comments', ['project' => 'SM', 'label' => 'question'])['items'];
        $this->call('comment_on_issue', $issue + ['body' => 'The user said in the session: remove `round()` in 3.0.', 'label' => 'decision', 'replaces' => $open[0]['id']]);

        self::assertSame([['Remove `round()` in 3.0?']], array_map(static fn (array $comment): array => array_column($comment['questions'], 'question'), $open));
        self::assertSame([], $this->call('list_comments', ['project' => 'SM', 'label' => 'question'])['items']);
    }

    #[TestDox('After a dismissal of the user, list_comments with the label dismissal lists it, and with the label question no longer lists the question comment')]
    public function testListCommentsAfterADismissal(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change'])['key']];
        $this->call('comment_on_issue', $issue + ['body' => 'One choice before the plan.', 'questions' => [['question' => 'Remove `round()` in 3.0?']]]);
        $asked = $this->call('list_comments', ['project' => 'SM', 'label' => 'question'])['items'][0]['id'];
        $this->client->loginUser($this->user, 'web');
        self::assertSame(201, self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/comments', ['body' => '', 'replaces' => $asked, 'dismiss' => true])->getStatusCode());

        self::assertSame(
            [['dismissal', 'Dismissed.', ['Remove `round()` in 3.0?']]],
            array_map(static fn (array $comment): array => [$comment['label'], $comment['body'], array_column($comment['questions'], 'question')], $this->call('list_comments', ['project' => 'SM', 'label' => 'dismissal'])['items']),
        );
        self::assertSame([], $this->call('list_comments', ['project' => 'SM', 'label' => 'question'])['items']);
    }

    #[TestDox('A link needs a note, has the kind relates by default, and is on both issues, and a link to the issue itself is refused')]
    public function testLinks(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round the shipping totals', 'category' => 'change']);
        $note = 'SM-2 came up while SM-1 was in progress.';

        self::assertJsonRpcError($this->mcp->callToolResponse('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1']), -32602);
        $blankNote = $this->refusal('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => ' ']);
        $linked = $this->call('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => $note]);
        $origin = $this->call('get_issue', ['issue' => 'SM-1']);
        $followUp = $this->call('get_issue', ['issue' => 'SM-2']);
        $selfLink = $this->refusal('link_issues', ['issue' => 'SM-1', 'target' => 'SM-1', 'note' => $note]);

        self::assertSame(['note', 'target'], [$blankNote['field'], $selfLink['field']]);
        self::assertSame('SM-2', $linked['key']);
        self::assertSame([['SM-2', 'relates', $note, self::AGENT_NAME]], array_map(static fn (array $link): array => [$link['issue']['key'], $link['kind'], $link['note'], $link['createdBy']['agentName']], $origin['links']));
        self::assertSame(['SM-1'], array_map(static fn (array $link): string => $link['issue']['key'], $followUp['links']));
        self::assertSame([], $origin['thread'], 'A link writes no entry.');
    }

    #[TestDox('A blocked_by link reads as blocked_by on the issue and as blocks on the target, and a second call for the pair, in either order, replaces the link')]
    public function testBlockedByLinkAndItsReplacement(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'category' => 'change']);
        $links = fn (string $issue): array => array_map(static fn (array $link): array => [$link['issue']['key'], $link['kind'], $link['note']], $this->call('get_issue', ['issue' => $issue])['links']);

        $this->call('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => 'SM-1 adds the mode.', 'kind' => 'blocked_by']);
        $blocked = [$links('SM-2'), $links('SM-1')];
        $this->call('link_issues', ['issue' => 'SM-1', 'target' => 'SM-2', 'note' => 'The same rounding.']);
        $otherOrder = [$links('SM-2'), $links('SM-1')];
        $this->call('link_issues', ['issue' => 'SM-1', 'target' => 'SM-2', 'note' => 'SM-2 names the mode.', 'kind' => 'blocked_by']);

        self::assertSame([[['SM-1', 'blocked_by', 'SM-1 adds the mode.']], [['SM-2', 'blocks', 'SM-1 adds the mode.']]], $blocked);
        self::assertSame([[['SM-1', 'relates', 'The same rounding.']], [['SM-2', 'relates', 'The same rounding.']]], $otherOrder);
        self::assertSame([[['SM-1', 'blocks', 'SM-2 names the mode.']], [['SM-2', 'blocked_by', 'SM-2 names the mode.']]], [$links('SM-2'), $links('SM-1')], 'The same order again.');
        self::assertJsonRpcError($this->mcp->callToolResponse('link_issues', ['issue' => 'SM-1', 'target' => 'SM-2', 'note' => 'SM-2 names the mode.', 'kind' => 'blocks']), -32602);
    }

    #[TestDox('A token of one repository sees only the issues, the comments, and the updates of its project, and no project without a repository')]
    public function testRepositoryTokenSeesItsProject(): void
    {
        $tenantId = $this->user->getTenantId();
        $alpha = self::storeRepository($tenantId, 'alpha');
        self::storeProject($tenantId, 'SM', repository: $alpha);
        self::storeProject($tenantId, 'DOC', repository: self::storeRepository($tenantId, 'beta'));
        self::storeProject($tenantId, 'OPS');

        foreach (['SM', 'DOC', 'OPS'] as $project) {
            $this->call('create_issue', ['project' => $project, 'title' => "An issue of {$project}", 'category' => 'change']);
            $this->call('comment_on_issue', ['issue' => "{$project}-1", 'body' => "A comment of {$project}."]);
        }

        $scoped = new McpTestClient($this->client, self::storeToken($this->user, $alpha, name: 'scoped agent')[1]);
        $allProjects = $this->call('list_projects', []);
        $projects = self::assertToolResult($scoped, $scoped->callTool('list_projects'), 'list_projects');
        $issues = self::assertToolResult($scoped, $scoped->callTool('list_issues', ['project' => 'SM']), 'list_issues');
        $comments = self::assertToolResult($scoped, $scoped->callTool('list_comments', ['project' => 'SM']), 'list_comments');
        $updates = self::assertToolResult($scoped, $scoped->callTool('get_issue_updates', ['project' => 'SM', 'cursor' => 0]), 'get_issue_updates');

        self::assertSame([['DOC', ['beta']], ['OPS', []], ['SM', ['alpha']]], array_map(static fn (array $project): array => [$project['key'], $project['repositories']], $allProjects['items']));
        self::assertSame([['key' => 'SM', 'name' => 'Project SM', 'approvalMode' => 'self', 'approvalGuidelines' => null, 'repositories' => ['alpha']]], $projects['items']);
        self::assertSame(['SM-1'], array_column($issues['items'], 'key'));
        self::assertSame(['A comment of SM.'], array_column($comments['items'], 'body'));
        self::assertSame([['SM-1', 'A comment of SM.']], array_map(static fn (array $item): array => [$item['issue']['key'], $item['entry']['body']], $updates['entries']), 'The entries of another token name.');
        self::assertSame('SM-2', self::assertToolResult($scoped, $scoped->callTool('create_issue', ['project' => 'SM', 'title' => 'From the scoped agent', 'category' => 'change']), 'create_issue')['key']);

        foreach (['DOC', 'OPS'] as $hidden) {
            self::assertToolError($scoped->callTool('list_issues', ['project' => $hidden]), 'not_found');
            self::assertToolError($scoped->callTool('list_comments', ['project' => $hidden]), 'not_found');
            self::assertToolError($scoped->callTool('get_issue_updates', ['project' => $hidden]), 'not_found');
            self::assertToolError($scoped->callTool('create_issue', ['project' => $hidden, 'title' => 'Hidden', 'category' => 'change']), 'not_found');
            self::assertToolError($scoped->callTool('get_issue', ['issue' => "{$hidden}-1"]), 'not_found');
            self::assertToolError($scoped->callTool('link_issues', ['issue' => 'SM-1', 'target' => "{$hidden}-1", 'note' => 'The same change.']), 'not_found');
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The structuredContent, checked against the output schema of the tool
     */
    private function call(string $tool, array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, self::withSession($tool, $arguments)), $tool);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{field: string, message: string} The violation of an invalid_input result
     */
    private function refusal(string $tool, array $arguments): array
    {
        $violations = self::assertToolError($this->mcp->callTool($tool, self::withSession($tool, $arguments)), 'invalid_input')['details']['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertCount(1, $violations);

        return $violations[0];
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

    /**
     * @return array{kind: string, userId: string, name: string} The user of the token as an IssueParty
     */
    private function userParty(): array
    {
        return ['kind' => 'user', 'userId' => $this->user->getId()->toString(), 'name' => $this->user->getLogin()];
    }

    /**
     * The approval of the user, which has no tool: the plan approval of the web interface.
     */
    private function storeUserApproval(string $issueKey, string $planRevision): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $number = IssueKey::fromString($issueKey, 'issue')->number;
        self::getContainer()->get(TenantFilter::class)->suspend(function () use ($entityManager, $number, $planRevision): void {
            $issue = $entityManager->getRepository(Issue::class)->findOneBy(['number' => $number]) ?? self::fail("No issue {$number}.");
            $approval = IssueEntry::planApproved($issue, Actor::user($this->user->getId()), null, null, IssueEntryId::fromString($planRevision), Clock::get()->now());
            $entityManager->persist($approval);
            $issue->approvePlan($approval);
            $entityManager->flush();
        });
        $entityManager->clear();
    }
}
