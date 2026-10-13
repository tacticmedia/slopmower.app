<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Domain\Identity\ReviewId;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use App\Tests\Support\E2E\RequestScreens;
use App\Tests\Support\Git\FixtureRepositories;
use PHPUnit\Framework\Attributes\TestDox;

final class SuggestionFlowTest extends FullStackTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string BODY = 'Write the basis-point scale as `10_000`.';
    private const string READY_NOTE = 'Wrote the scale as 10_000.';
    private const string CODE_THREAD = '[data-testid="code-view"] '.self::SAVED_THREAD;
    private const int WAIT_MILLISECONDS = 5000;

    #[TestDox('The agent starts a review and suggests over MCP, the user accepts live, and the agent finishes the accepted request')]
    public function testUserAcceptsTheAgentsSuggestion(): void
    {
        $this->commenter = $this->user();
        $directory = $this->localSource(FixtureRepositories::codeView());
        self::connectedRepository($this->commenter, $directory);
        $agent = $this->agent();
        $this->reviewId = ReviewId::fromString((string) self::agentCalls($agent, 'start_review', ['repository' => $directory])['id']);
        $this->signInAs($this->commenter->getLogin());
        $this->openFile(FixtureRepositories::SMALL_FILE);
        // The local transport of the e2e hub keeps no history, so the subscription must exist before the suggestion.
        $this->expect($this->page->locator('[data-live="connected"]'))->toBeAttached();

        $suggested = self::agentCalls($agent, 'suggest_request', [
            'review' => $this->reviewId->toString(),
            'body' => self::BODY,
            'anchorKind' => 'line',
            'path' => FixtureRepositories::SMALL_FILE,
            'startLine' => 11,
            'endLine' => 11,
        ]);

        $requestId = (string) $suggested['request']['id'];
        $this->expect($this->page->locator(self::CODE_THREAD.' header [data-testid="suggestion"]'))->withTimeout(self::WAIT_MILLISECONDS)->toHaveExactText('Suggestion');
        $actions = $this->page->locator(self::CODE_THREAD.' [data-testid="suggestion-actions"]');
        $actions->getByRole('checkbox', ['name' => 'Ask for a plan first', 'exact' => true])->uncheck();
        $actions->getByRole('button', ['name' => 'Accept', 'exact' => true])->click();
        $this->expect($this->page->locator(self::CODE_THREAD))->toContainText('accepted the suggestion');

        $updates = self::agentCalls($agent, 'get_updates', ['review' => $this->reviewId->toString()]);
        self::assertSame([$requestId], array_column(array_column($updates['requests'], 'request'), 'id'));
        $accepted = $updates['requests'][0];
        self::assertSame(['accepted', 'agent', false], [$accepted['request']['suggestion'], $accepted['request']['awaiting'], $accepted['request']['requireApproval']]);
        self::assertSame(['request.suggestion_accepted'], array_column($accepted['newEvents'], 'type'));

        self::agentCalls($agent, 'claim_request', ['id' => $requestId]);
        $fixed = str_replace('10000)', '10_000)', FixtureRepositories::codeView()[FixtureRepositories::SMALL_FILE]);
        $fix = self::commitInLocalSource($directory, [FixtureRepositories::SMALL_FILE => $fixed], "Write the scale as 10_000 ({$requestId})");
        self::agentCalls($agent, 'link_commits', ['commitSha' => $fix, 'requestIds' => [$requestId]]);
        self::agentCalls($agent, 'mark_ready_for_review', ['id' => $requestId, 'note' => self::READY_NOTE]);

        $this->openRequest($requestId);
        $this->waitForStatus('Ready for review');
        $this->expect($this->page->locator(self::DETAIL.' [data-testid="thread"]'))->toContainText(self::READY_NOTE);
    }
}
