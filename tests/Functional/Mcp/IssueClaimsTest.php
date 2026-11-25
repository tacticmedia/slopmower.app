<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The claims of D130 in docs/specs/mcp.md "Issue tools". Two sessions of one token work on SM-1, which an agent created
 * in todo at 09:00. The signed-in user reads and writes it through REST.
 */
final class IssueClaimsTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use JsonApiAssertions;
    use McpAssertions;
    use ResetDatabase;

    private const string PLAN = '1. Add `TaxRounding::perInvoice()`. 2. Test three lines of 0.335.';
    private const string RESULT = 'Added `TaxRounding::perInvoice()` in a1b2c3d. No deviation.';
    private const string HOLDER = 'billing-3f9a';
    private const string OTHER = 'billing-77c0';
    private const string ISSUE = 'issue.schema.json';

    private KernelBrowser $client;

    private string $secret;

    private McpTestClient $mcp;

    /** @var array<string, mixed> */
    private array $created;

    protected function setUp(): void
    {
        self::at('09:00');
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        [, $this->secret] = self::storeToken($user);
        $this->mcp = new McpTestClient($this->client, $this->secret);
        self::storeProject($user->getTenantId(), 'SM');
        $this->created = $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => 'Invoice totals round each line.', 'status' => 'todo']);
    }

    #[TestDox('create_issue takes no claim, the first write of a session takes it, and get_issue and list_issues show it')]
    public function testFirstWriteTakesTheClaim(): void
    {
        $written = $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN]);
        $claim = ['holder' => $this->created['createdBy'], 'session' => self::HOLDER, 'since' => '2026-11-25T09:00:00Z', 'until' => '2026-11-25T11:00:00Z'];

        self::assertNull($this->created['claim']);
        self::assertSame($claim, $written['claim']);
        self::assertSame($claim, $this->call('get_issue', ['issue' => 'SM-1'])['issue']['claim']);
        self::assertSame($claim, $this->call('list_issues', ['project' => 'SM'])['items'][0]['claim']);
    }

    #[TestDox('Another session of the token is refused on session in update_issue, set_issue_status, and approve_plan, and its comment passes')]
    public function testAnotherSessionIsRefused(): void
    {
        $planRevision = $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN])['planRevision'];
        self::at('09:30');

        $refusals = [
            $this->refusal('update_issue', self::of(self::OTHER) + ['result' => self::RESULT]),
            $this->refusal('set_issue_status', self::of(self::OTHER) + ['status' => 'in_progress']),
            $this->refusal('approve_plan', self::of(self::OTHER) + ['planRevision' => $planRevision, 'reviewer' => 'plan-reviewer', 'reviewNote' => 'The plan covers the rounding and its test.']),
        ];
        $commented = $this->call('comment_on_issue', self::of(self::OTHER) + ['body' => 'SM-1 is held, so this session takes SM-2.']);

        self::assertSame(array_fill(0, 3, ['field' => 'session', 'message' => 'Session billing-3f9a of claude-code laptop holds SM-1 until 2026-11-25T11:00:00Z. Take another issue.']), $refusals);
        self::assertSame([self::HOLDER, '2026-11-25T11:00:00Z'], [$commented['claim']['session'], $commented['claim']['until']], 'The comment takes no claim.');
    }

    #[TestDox('A write that changes nothing and a comment of the holder move the end and keep the start')]
    public function testHolderRenewsTheClaim(): void
    {
        $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN]);
        self::at('10:00');
        $written = $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN])['claim'];
        self::at('11:30');
        $commented = $this->call('comment_on_issue', self::of(self::HOLDER) + ['body' => 'The test is next.'])['claim'];

        self::assertSame(['2026-11-25T09:00:00Z', '2026-11-25T12:00:00Z'], [$written['since'], $written['until']]);
        self::assertSame(['2026-11-25T09:00:00Z', '2026-11-25T13:30:00Z'], [$commented['since'], $commented['until']]);
    }

    #[TestDox('2 hours after the last write of the holder, the claim reads as none, and another session takes the issue')]
    public function testAnotherSessionTakesTheIssueAfterTheEnd(): void
    {
        $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN]);
        self::at('11:00');

        $ended = $this->call('get_issue', ['issue' => 'SM-1'])['issue']['claim'];
        $taken = $this->call('set_issue_status', self::of(self::OTHER) + ['status' => 'in_progress'])['claim'];

        self::assertNull($ended);
        self::assertSame([self::OTHER, '2026-11-25T11:00:00Z', '2026-11-25T13:00:00Z'], [$taken['session'], $taken['since'], $taken['until']]);
    }

    #[TestDox('A status change ends the claim when it goes to backlog, awaiting_user_input, in_review, done, or wont_do, or leaves in_progress')]
    #[DataProvider('passingMoves')]
    public function testStatusChangeThatPassesTheIssueOnEndsTheClaim(string $status, bool $fromInProgress): void
    {
        $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN, 'result' => self::RESULT]);

        if ($fromInProgress) {
            self::assertSame(self::HOLDER, $this->call('set_issue_status', self::of(self::HOLDER) + ['status' => 'in_progress'])['claim']['session'], 'todo to in_progress keeps it.');
        }

        self::assertNull($this->call('set_issue_status', self::of(self::HOLDER) + ['status' => $status, 'note' => 'SM-9 covers it.'])['claim']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function passingMoves(): iterable
    {
        yield 'backlog' => ['backlog', false];
        yield 'awaiting_user_input' => ['awaiting_user_input', false];
        yield 'in_review' => ['in_review', true];
        yield 'done' => ['done', true];
        yield 'wont_do' => ['wont_do', false];
        yield 'todo from in_progress' => ['todo', true];
    }

    #[TestDox('A refused write takes no claim')]
    public function testRefusedWriteTakesNoClaim(): void
    {
        $refusal = $this->refusal('set_issue_status', self::of(self::HOLDER) + ['status' => 'in_progress']);

        self::assertSame('plan', $refusal['field']);
        self::assertNull($this->call('get_issue', ['issue' => 'SM-1'])['issue']['claim']);
    }

    #[TestDox('A user writes past a claim and takes none, Release of a user removes it, and a token is refused there')]
    public function testUserPassesAndReleasesTheClaim(): void
    {
        $this->call('update_issue', self::of(self::HOLDER) + ['plan' => self::PLAN]);
        self::at('09:30');

        $byUser = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', '/api/v1/issues/SM-1', ['result' => self::RESULT]), 200, self::ISSUE);
        $byToken = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/issues/SM-1/release'), 403, 'forbidden');
        self::at('09:40');
        $released = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/release'), 200, self::ISSUE);
        $again = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/release'), 200, self::ISSUE);

        self::assertSame([self::HOLDER, '2026-11-25T11:00:00Z'], [$byUser['claim']['session'], $byUser['claim']['until']]);
        self::assertSame('This endpoint needs a session. API tokens may not call it.', $byToken['message']);
        self::assertSame([null, $byUser['updatedAt']], [$released['claim'], $released['updatedAt']], 'The release sets no updatedAt.');
        self::assertSame($released, $again);
        self::assertSame(self::OTHER, $this->call('set_issue_status', self::of(self::OTHER) + ['status' => 'in_progress'])['claim']['session']);
    }

    #[TestDox('I3, I7, and I8 refuse a token without session, and a status change of the user that passes the issue on ends the claim')]
    public function testRestWritesOfTokenAndUser(): void
    {
        $withoutSession = array_map(fn (array $call): array => self::assertApiError(self::bearerRequest($this->client, $this->secret, ...$call), 422, 'invalid_input')['details']['violations'] ?? [], [
            ['POST', '/api/v1/issues/SM-1/comments', ['body' => 'The test is next.']],
            ['PATCH', '/api/v1/issues/SM-1', ['plan' => self::PLAN]],
            ['POST', '/api/v1/issues/SM-1/status', ['status' => 'backlog']],
        ]);
        $taken = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'PATCH', '/api/v1/issues/SM-1', ['plan' => self::PLAN, 'session' => self::HOLDER]), 200, self::ISSUE);
        $passedOn = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/issues/SM-1/status', ['status' => 'awaiting_user_input']), 200, self::ISSUE);

        self::assertSame(array_fill(0, 3, [['field' => 'session', 'message' => 'A token names its agent session in each write.']]), $withoutSession);
        self::assertSame(self::HOLDER, $taken['claim']['session']);
        self::assertNull($passedOn['claim']);
    }

    private static function at(string $time): void
    {
        self::mockTime("2026-11-25 {$time}:00");
    }

    /**
     * @return array{issue: string, session: string}
     */
    private static function of(string $session): array
    {
        return ['issue' => 'SM-1', 'session' => $session];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $arguments), $tool);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{field: string, message: string}
     */
    private function refusal(string $tool, array $arguments): array
    {
        $violations = self::assertToolError($this->mcp->callTool($tool, $arguments), 'invalid_input')['details']['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertCount(1, $violations);

        return $violations[0];
    }
}
