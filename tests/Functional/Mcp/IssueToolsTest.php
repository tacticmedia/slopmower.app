<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Domain\Actor\Actor;
use App\Domain\Identity\IssueEntryId;
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

/**
 * docs/specs/mcp.md sections 4.19 to 4.27. The agent of a token for all repositories works on the issues of the
 * project SM.
 */
final class IssueToolsTest extends WebTestCase
{
    use ApiFixtures;
    use McpAssertions;
    use ResetDatabase;

    private const string PROBLEM = 'Invoice totals round each line. Done when a test with three lines of 0.335 gives 1.01.';
    private const string PLAN = '1. Add `TaxRounding::perInvoice()`. 2. Test three lines of 0.335.';
    private const string RESULT = 'Added `TaxRounding::perInvoice()` in a1b2c3d. No deviation.';
    private const string REVIEW_NOTE = 'The plan covers the rounding and its test.';
    private const string REVIEWER = 'plan-reviewer';
    private const string AGENT_NAME = 'claude-code laptop';

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

        $first = $this->call('create_issue', ['project' => 'SM', 'title' => '  Round invoice totals once  ']);
        $second = $this->call('create_issue', ['project' => 'SM', 'title' => 'Show the rounding mode', 'problem' => self::PROBLEM, 'status' => 'todo']);
        $other = $this->call('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding']);
        $page = $this->call('list_issues', ['project' => 'SM']);
        $todo = $this->call('list_issues', ['project' => 'SM', 'status' => 'todo']);
        $detail = $this->call('get_issue', ['issue' => 'SM-2']);

        self::assertSame(['SM-1', 'SM-2', 'DOC-1'], [$first['key'], $second['key'], $other['key']]);
        self::assertSame(['Round invoice totals once', 'draft', null, null, 'agent', self::AGENT_NAME], [$first['title'], $first['status'], $first['problem'], $first['planRevision'], $first['createdBy']['kind'], $first['createdBy']['agentName']]);
        self::assertSame(['key' => 'SM', 'name' => 'Project SM', 'approvalMode' => 'self'], $first['project']);
        self::assertSame(['SM-2', 'SM-1'], array_column($page['items'], 'key'), 'Newest first.');
        self::assertSame(['key' => 'SM-2', 'title' => 'Show the rounding mode', 'status' => 'todo', 'updatedAt' => $second['updatedAt']], $todo['items'][0]);
        self::assertCount(1, $todo['items']);
        self::assertSame($second, $detail['issue']);
        self::assertSame([], $detail['links']);
        self::assertSame([['section_changed', 'problem', null]], array_map(static fn (array $entry): array => [$entry['kind'], $entry['section'], $entry['body']], $detail['thread']), 'A section_changed entry has its section and no text.');
        self::assertSame('problem', $this->refusal('create_issue', ['project' => 'SM', 'title' => 'Without a problem', 'status' => 'todo'])['field']);
        self::assertSame(['SM-3'], [$this->call('create_issue', ['project' => 'SM', 'title' => 'After a refusal'])['key']], 'A refused issue takes no number.');
    }

    #[TestDox('Each row of the rules table refuses and allows, as the approval mode says')]
    #[DataProvider('approvalModes')]
    public function testRulesOfEachApprovalMode(ApprovalMode $mode): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', $mode);
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once'])['key']];
        $review = ['note' => 'The test passes.', 'reviewer' => 'result-reviewer'];

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
        $duplicate = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'A duplicate'])['key'], 'status' => 'wont_do'];

        if (ApprovalMode::User === $mode) {
            self::assertSame('status', $this->refusal('set_issue_status', $issue + ['status' => 'done'] + $review)['field']);
            self::assertSame('status', $this->refusal('set_issue_status', $duplicate + $review)['field']);
            self::assertSame(['draft', 'backlog', 'todo', 'in_progress', 'awaiting_user_input'], $this->call('get_issue', $issue)['issue']['allowedStatuses']);

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
     * @return iterable<string, array{ApprovalMode}>
     */
    public static function approvalModes(): iterable
    {
        foreach (ApprovalMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[TestDox('A plan with new text clears the approval, and the same text keeps it')]
    public function testNewPlanTextClearsTheApproval(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM', ApprovalMode::AgentReview);
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'problem' => self::PROBLEM, 'status' => 'todo'])['key']];
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
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once'])['key']];
        $userIssue = ['issue' => $this->call('create_issue', ['project' => 'DOC', 'title' => 'Describe the rounding'])['key']];

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
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'problem' => self::PROBLEM, 'status' => 'todo'])['key']];
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

    #[TestDox('A comment and a status change are in the thread with their actor')]
    public function testThreadHoldsCommentsAndStatusChanges(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $issue = ['issue' => $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once'])['key']];

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

    #[TestDox('A link needs a note and is on both issues, and a second link of the pair or a link to the issue itself is refused')]
    public function testLinks(): void
    {
        self::storeProject($this->user->getTenantId(), 'SM');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once']);
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round the shipping totals']);
        $note = 'SM-2 came up while SM-1 was in progress.';

        self::assertJsonRpcError($this->mcp->callToolResponse('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1']), -32602);
        $blankNote = $this->refusal('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => ' ']);
        $linked = $this->call('link_issues', ['issue' => 'SM-2', 'target' => 'SM-1', 'note' => $note]);
        $origin = $this->call('get_issue', ['issue' => 'SM-1']);
        $followUp = $this->call('get_issue', ['issue' => 'SM-2']);
        $secondLink = $this->refusal('link_issues', ['issue' => 'SM-1', 'target' => 'SM-2', 'note' => $note]);
        $selfLink = $this->refusal('link_issues', ['issue' => 'SM-1', 'target' => 'SM-1', 'note' => $note]);

        self::assertSame(['note', 'target', 'target'], [$blankNote['field'], $secondLink['field'], $selfLink['field']]);
        self::assertSame('SM-2', $linked['key']);
        self::assertSame([['SM-2', $note, self::AGENT_NAME]], array_map(static fn (array $link): array => [$link['issue']['key'], $link['note'], $link['createdBy']['agentName']], $origin['links']));
        self::assertSame(['SM-1'], array_map(static fn (array $link): string => $link['issue']['key'], $followUp['links']));
        self::assertSame([], $origin['thread'], 'A link writes no entry.');
    }

    #[TestDox('A token of one repository sees only the issues of its project, and no project without a repository')]
    public function testRepositoryTokenSeesItsProject(): void
    {
        $tenantId = $this->user->getTenantId();
        $alpha = self::storeRepository($tenantId, 'alpha');
        self::storeProject($tenantId, 'SM', repository: $alpha);
        self::storeProject($tenantId, 'DOC', repository: self::storeRepository($tenantId, 'beta'));
        self::storeProject($tenantId, 'OPS');

        foreach (['SM', 'DOC', 'OPS'] as $project) {
            $this->call('create_issue', ['project' => $project, 'title' => "An issue of {$project}"]);
        }

        $scoped = new McpTestClient($this->client, self::storeToken($this->user, $alpha, name: 'scoped agent')[1]);
        $allProjects = $this->call('list_projects', []);
        $projects = self::assertToolResult($scoped, $scoped->callTool('list_projects'), 'list_projects');
        $issues = self::assertToolResult($scoped, $scoped->callTool('list_issues', ['project' => 'SM']), 'list_issues');

        self::assertSame([['DOC', ['beta']], ['OPS', []], ['SM', ['alpha']]], array_map(static fn (array $project): array => [$project['key'], $project['repositories']], $allProjects['items']));
        self::assertSame([['key' => 'SM', 'name' => 'Project SM', 'approvalMode' => 'self', 'repositories' => ['alpha']]], $projects['items']);
        self::assertSame(['SM-1'], array_column($issues['items'], 'key'));
        self::assertSame('SM-2', self::assertToolResult($scoped, $scoped->callTool('create_issue', ['project' => 'SM', 'title' => 'From the scoped agent']), 'create_issue')['key']);

        foreach (['DOC', 'OPS'] as $hidden) {
            self::assertToolError($scoped->callTool('list_issues', ['project' => $hidden]), 'not_found');
            self::assertToolError($scoped->callTool('create_issue', ['project' => $hidden, 'title' => 'Hidden']), 'not_found');
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
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $arguments), $tool);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{field: string, message: string} The violation of an invalid_input result
     */
    private function refusal(string $tool, array $arguments): array
    {
        $violations = self::assertToolError($this->mcp->callTool($tool, $arguments), 'invalid_input')['details']['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertCount(1, $violations);

        return $violations[0];
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
            $approval = IssueEntry::planApproved($issue, Actor::user($this->user->getId()), 'jan', 'Approved by the user.', IssueEntryId::fromString($planRevision), Clock::get()->now());
            $entityManager->persist($approval);
            $issue->approvePlan($approval);
            $entityManager->flush();
        });
        $entityManager->clear();
    }
}
