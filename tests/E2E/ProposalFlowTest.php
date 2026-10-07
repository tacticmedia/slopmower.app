<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use App\Tests\Support\E2E\RequestScreens;
use App\Tests\Support\Git\FixtureRepositories;
use PHPUnit\Framework\Attributes\TestDox;

final class ProposalFlowTest extends FullStackTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string BODY = 'Extract the tax calculation into its own method.';
    private const string PLAN = 'Move the tax term into a private `tax()` method.';
    private const string READY_NOTE = 'Extracted the tax method as proposed.';
    private const string PROPOSAL = '[data-testid="proposal"]';
    private const int WAIT_MILLISECONDS = 5000;

    #[TestDox('A request with Ask for a plan first: the agent proposes over MCP, the user approves live, and the agent finishes')]
    public function testUserApprovesTheAgentsProposal(): void
    {
        $this->commenter = $this->user();
        $directory = $this->localSource(FixtureRepositories::codeView());
        $this->reviewId = self::startedReview($this->commenter, self::connectedRepository($this->commenter, $directory), 'Proposals');
        $this->signInAs($this->commenter->getLogin());
        $this->visit("/reviews/{$this->reviewId}/requests");
        $this->page->getByRole('button', ['name' => 'New request', 'exact' => true])->click();
        $this->expect($this->page->getByRole('checkbox', ['name' => 'Ask for a plan first', 'exact' => true]))->toBeChecked();
        $this->page->locator('textarea[aria-label="New request"]')->fill(self::BODY);
        $this->page->locator('form[data-testid="comment-box"]')->getByRole('button', ['name' => 'Add single comment', 'exact' => true])->click();
        $this->expect($this->page->locator('[data-testid="request-list"]')->getByRole('link', ['name' => self::BODY, 'exact' => true]))->toBeVisible();

        $agent = $this->agent();
        [$listed] = self::agentCalls($agent, 'list_requests', ['review' => $this->reviewId->toString()])['items'];
        self::assertTrue($listed['requireApproval']);
        $requestId = (string) $listed['id'];
        self::agentCalls($agent, 'claim_request', ['id' => $requestId]);
        $this->openRequest($requestId);
        // The local transport of the e2e hub keeps no history, so the subscription must exist before the proposal.
        $this->expect($this->page->locator('[data-live="connected"]'))->toBeAttached();

        $proposed = self::agentCalls($agent, 'propose_solution', ['id' => $requestId, 'plan' => self::PLAN, 'diff' => self::diff()]);

        self::assertSame('pending', $proposed['request']['proposal']['state']);
        $diffFile = $this->page->locator(self::PROPOSAL.' [data-testid="proposal-diff-file"]');
        $this->expect($diffFile)->withTimeout(self::WAIT_MILLISECONDS)->toHaveAttribute('data-path', FixtureRepositories::SMALL_FILE);
        $this->expect($diffFile->locator(self::CODE_HOST.' [data-line-type]')->first())->toBeAttached();
        $this->page->locator(self::PROPOSAL)->getByRole('button', ['name' => 'Approve', 'exact' => true])->click();
        $this->expect($this->page->locator(self::PROPOSAL.' [data-testid="proposal-state"]'))->toHaveExactText('Approved');

        $fix = self::commitInLocalSource($directory, [FixtureRepositories::SMALL_FILE => self::fixedSmallFile()], "Extract tax() ({$requestId})");
        self::agentCalls($agent, 'link_commits', ['commitSha' => $fix, 'requestIds' => [$requestId]]);
        self::agentCalls($agent, 'mark_ready_for_review', ['id' => $requestId, 'note' => self::READY_NOTE]);

        $this->page->reload();
        $this->waitForStatus('Ready for review');
        $this->expect($this->page->locator(self::DETAIL.' [data-testid="thread"]'))->toContainText(self::READY_NOTE);
    }

    private static function diff(): string
    {
        return <<<'DIFF'
            diff --git a/src/Billing/InvoiceTotals.php b/src/Billing/InvoiceTotals.php
            --- a/src/Billing/InvoiceTotals.php
            +++ b/src/Billing/InvoiceTotals.php
            @@ -9,5 +9,10 @@
                 public function total(int $net, int $taxBasisPoints): int
                 {
            -        return $net + intdiv($net * $taxBasisPoints, 10000);
            +        return $net + $this->tax($net, $taxBasisPoints);
            +    }
            +
            +    private function tax(int $net, int $taxBasisPoints): int
            +    {
            +        return intdiv($net * $taxBasisPoints, 10000);
                 }
             }

            DIFF;
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
