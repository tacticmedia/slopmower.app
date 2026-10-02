<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Domain\Identity\ReviewId;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\McpHttpClient;
use App\Tests\Support\E2E\RequestScreens;
use App\Tests\Support\E2E\TokenSettings;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class VerticalSliceTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;
    use TokenSettings;

    private const string COMMENT = 'Extract the tax calculation into its own method.';
    private const string READY_NOTE = 'Extracted the tax method. The unit tests pass.';

    #[TestDox('The user and the agent complete a request through the browser and MCP')]
    public function testUserAndAgentCompleteARequest(): void
    {
        $this->commenter = $this->user();
        $directory = $this->localSource(FixtureRepositories::codeView());
        $client = self::browserSignedInAs($this->commenter);
        $this->reviewId = self::connectAndStartReview($client, $directory);

        $this->openFile($client, FixtureRepositories::SMALL_FILE);
        self::waitForHighlighting($client, 9);
        self::dragFromPlus($client, 9, 12);
        self::addSingleComment($client, self::focusedCommentBoxBelow($client, 12), self::COMMENT, '[slot="annotation-12"]');

        self::clickLink($client, '//header//nav[@aria-label="Main"]//a[normalize-space()="Settings"]');
        $client->waitFor('#api-tokens');
        $secret = $this->createToken($client, 'claude-code laptop');
        $this->closeIssuedToken($client);

        $client->request('GET', "/reviews/{$this->reviewId}/requests");
        self::clickLink($client, '//*[@data-testid="request-list"]//a[normalize-space()="'.self::COMMENT.'"]');
        $client->waitFor(self::DETAIL.' [data-testid="thread-comment"]');
        self::waitForStatus($client, 'Open');

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

        $client->reload();
        self::waitForStatus($client, 'Ready for review');
        $client->waitForElementToContain(self::DETAIL.' [data-testid="thread"]', self::READY_NOTE);
        self::assertSame('available', $client->getCrawler()->filter('[data-testid="commit-link"]')->attr('data-state'));
        self::assertSelectorTextContains('[data-testid="commit-link"]', substr($fix, 0, 12));
        self::assertSelectorTextContains('[data-testid="commit-link"]', "Extract tax() ({$requestId})");

        self::clickRequestButton($client, 'Resolve conversation');

        self::waitForStatus($client, 'Verified');
        self::assertSame(['verified'], array_column(self::agentCalls($agent, 'list_requests', ['review' => $this->reviewId->toString()])['items'], 'status'));
    }

    /**
     * Connects the source on the repositories screen and starts a review at main on the repository screen.
     */
    private static function connectAndStartReview(Client $client, string $directory): ReviewId
    {
        $client->request('GET', '/repositories');
        $connect = "[data-testid=\"local-candidates\"] button[aria-label=\"Connect {$directory}\"]";
        $client->waitFor($connect);
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector($connect))->click();
        $client->waitForElementToContain('[data-testid="connected-repositories"] [data-testid="sync-state"]', 'Ready');

        self::clickLink($client, "//*[@data-testid=\"connected-repositories\"]//a[normalize-space()=\"{$directory}\"]");
        $client->waitFor('[data-testid="start-review-form"] input[name="ref"][value="main"]');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-testid="start-review-form"] button[type="submit"]'))->click();
        $client->wait()->until(WebDriverExpectedCondition::urlMatches('#/reviews/[0-9A-HJKMNP-TV-Z]{26}$#'));

        return ReviewId::fromString(basename((string) parse_url($client->getCurrentURL(), \PHP_URL_PATH)));
    }

    private static function clickLink(Client $client, string $xpath): void
    {
        $client->waitFor($xpath);
        $client->getWebDriver()->findElement(WebDriverBy::xpath($xpath))->click();
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
