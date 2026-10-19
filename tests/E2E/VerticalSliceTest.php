<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Domain\Identity\ReviewId;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use App\Tests\Support\E2E\McpHttpClient;
use App\Tests\Support\E2E\RequestScreens;
use App\Tests\Support\E2E\TokenSettings;
use App\Tests\Support\Git\FixtureRepositories;
use PHPUnit\Framework\Attributes\TestDox;

final class VerticalSliceTest extends FullStackTestCase
{
    use RequestScreens;
    use ResetDatabase;
    use TokenSettings;

    private const string COMMENT = 'Extract the tax calculation into its own method.';
    private const string READY_NOTE = 'Extracted the tax method. The unit tests pass.';
    private const string COMMIT_LINK = '[data-testid="commit-link"]';

    #[TestDox('The user and the agent complete a request through the browser and MCP')]
    public function testUserAndAgentCompleteARequest(): void
    {
        $this->commenter = $this->user();
        $directory = $this->localSource(FixtureRepositories::codeView());
        $this->signInAs($this->commenter->getLogin());
        $this->reviewId = $this->connectAndStartReview($directory);

        $this->openFile(FixtureRepositories::SMALL_FILE);
        $this->waitForHighlighting(9);
        $this->dragFromPlus(9, 12);
        $textarea = $this->focusedCommentBoxBelow(12);
        $this->page->locator('[slot="annotation-12"] form[data-testid="comment-box"]')->getByRole('checkbox', ['name' => 'Ask for a plan first', 'exact' => true])->uncheck();
        $this->addSingleComment($textarea, self::COMMENT, '[slot="annotation-12"]');

        $this->page->locator('header nav[aria-label="Main"]')->getByRole('link', ['name' => 'Settings', 'exact' => true])->click();
        $this->expect($this->page->locator('#api-tokens'))->toBeVisible();
        $secret = $this->createToken('claude-code laptop');
        $this->closeIssuedToken();

        $this->visit("/reviews/{$this->reviewId}/requests");
        $this->page->locator('[data-testid="request-list"]')->getByRole('link', ['name' => self::COMMENT, 'exact' => true])->click();
        $this->expect($this->page->locator(self::DETAIL.' [data-testid="thread-comment"]')->first())->toBeVisible();
        $this->waitForStatus('Open');

        $agent = McpHttpClient::connect($secret);
        $reviews = self::agentCalls($agent, 'list_reviews', ['repository' => $directory]);
        self::assertSame([$this->reviewId->toString()], array_column($reviews['items'], 'id'));
        $requests = self::agentCalls($agent, 'list_requests', ['review' => $this->reviewId->toString()]);
        self::assertCount(1, $requests['items']);
        self::assertSame(['startLine' => 9, 'endLine' => 12], $requests['items'][0]['anchor']['range']);
        $requestId = $requests['items'][0]['id'];
        self::agentCalls($agent, 'claim_request', ['id' => $requestId]);

        $fix = self::commitInLocalSource($directory, [FixtureRepositories::SMALL_FILE => self::fixedSmallFile()], "Extract tax() ({$requestId})");

        self::agentCalls($agent, 'link_commits', ['commitSha' => $fix, 'requestIds' => [$requestId]]);
        self::agentCalls($agent, 'mark_ready_for_review', ['id' => $requestId, 'note' => self::READY_NOTE]);

        $this->page->reload();
        $this->waitForStatus('Ready for review');
        $this->expect($this->page->locator(self::DETAIL.' [data-testid="thread"]'))->toContainText(self::READY_NOTE);
        $commitLink = $this->page->locator(self::COMMIT_LINK);
        $this->expect($commitLink)->toHaveAttribute('data-state', 'available');
        $this->expect($commitLink)->toContainText(substr($fix, 0, 12));
        $this->expect($commitLink)->toContainText("Extract tax() ({$requestId})");

        $this->clickRequestButton('Resolve conversation');

        $this->waitForStatus('Verified');
        self::assertSame(['verified'], array_column(self::agentCalls($agent, 'list_requests', ['review' => $this->reviewId->toString()])['items'], 'status'));
    }

    /**
     * Connects the source in the first onboarding step, which opens the repository screen, and starts a review at main.
     */
    private function connectAndStartReview(string $directory): ReviewId
    {
        $this->visit('/repositories');
        $this->page->locator("[data-testid=\"onboarding-local\"] button[aria-label=\"Connect {$directory}\"]")->click();
        $this->page->waitForURL(self::BASE_URI.'/repositories/*');

        $this->expect($this->page->locator('[data-testid="start-review-form"] input[name="ref"][value="main"]'))->toBeAttached();
        $this->page->locator('[data-testid="start-review-form"] button[type="submit"]')->click();
        $this->page->waitForURL(self::BASE_URI.'/reviews/*');

        return ReviewId::fromString(basename((string) parse_url($this->page->url(), \PHP_URL_PATH)));
    }

    private static function fixedSmallFile(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Billing;

            final class InvoiceTotals
            {
                public function total(int $net, int $taxBasisPoints): int
                {
                    return $net + $this->tax($net, $taxBasisPoints);
                }

                private function tax(int $net, int $taxBasisPoints): int
                {
                    return intdiv($net * $taxBasisPoints, 10000);
                }
            }

            PHP;
    }
}
