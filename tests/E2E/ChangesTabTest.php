<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\RequestScreens;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class ChangesTabTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;

    /** Each linked commit has a section, and each file of it a rendered diff. */
    private const string DIFFS_RENDERED = <<<'JS'
        const sections = [...document.querySelectorAll('[data-testid="commit-changes"]')];

        return sections.length === arguments[0] && sections.every((section) => {
            const files = [...section.querySelectorAll('[data-testid="commit-diff-file"]')];

            return files.length > 0 && files.every((file) => null !== file.querySelector('diffs-container')?.shadowRoot?.querySelector('[data-line-type]'));
        });
        JS;

    private const string FILE_PATHS_BY_COMMIT = <<<'JS'
        return Object.fromEntries([...document.querySelectorAll('[data-testid="commit-changes"]')].map((section) => [
            section.dataset.commitSha,
            [...section.querySelectorAll('[data-testid="commit-diff-file"]')].map((file) => file.dataset.path),
        ]));
        JS;

    #[TestDox('The Changes tab shows one FileDiff per linked commit with the anchored file first')]
    public function testChangesTabShowsFileDiffsWithAnchoredFileFirst(): void
    {
        $this->commenter = $this->user();
        $directory = $this->localSource(FixtureRepositories::codeView());
        $this->reviewId = self::startedReview($this->commenter, self::connectedRepository($this->commenter, $directory), 'Changes');
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.')->toString();
        $agent = $this->agent();
        self::agentCalls($agent, 'claim_request', ['id' => $requestId]);
        $extract = self::commitInLocalSource($directory, ['README.md' => "# Code view\n\nTax moved.\n", FixtureRepositories::SMALL_FILE => self::smallFile('$this->tax($net, $taxBasisPoints)')], 'Extract tax()');
        $round = self::commitInLocalSource($directory, ['CHANGELOG.md' => "Round the tax.\n", FixtureRepositories::SMALL_FILE => self::smallFile('$this->roundedTax($net, $taxBasisPoints)')], 'Round the tax');
        self::agentCalls($agent, 'link_commits', ['commitSha' => $extract, 'requestIds' => [$requestId]]);
        self::agentCalls($agent, 'link_commits', ['commitSha' => $round, 'requestIds' => [$requestId]]);
        self::agentCalls($agent, 'mark_ready_for_review', ['id' => $requestId, 'note' => 'Extracted and rounded the tax.']);
        $client = self::browserSignedInAs($this->commenter);
        self::openRequest($client, $requestId);

        $client->getWebDriver()->findElement(WebDriverBy::xpath('//nav[@aria-label="Request"]//a[contains(normalize-space(), "Changes")]'))->click();

        $client->wait()->until(static fn (): bool => true === $client->executeScript(self::DIFFS_RENDERED, [2]), 'The diffs of the linked commits did not render.');
        self::assertStringContainsString('tab=changes', $client->getCurrentURL());
        // Q3 breaks a tie of linkedAt by SHA, so the section order is not checked.
        self::assertEquals([
            $extract => [FixtureRepositories::SMALL_FILE, 'README.md'],
            $round => [FixtureRepositories::SMALL_FILE, 'CHANGELOG.md'],
        ], $client->executeScript(self::FILE_PATHS_BY_COMMIT));
    }

    /**
     * {@see FixtureRepositories::codeView()} with `$tax` as the tax term on line 11.
     */
    private static function smallFile(string $tax): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Billing;

            final class InvoiceTotals
            {
                public function total(int \$net, int \$taxBasisPoints): int
                {
                    return \$net + {$tax};
                }
            }

            PHP;
    }
}
