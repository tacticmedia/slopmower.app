<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use App\Tests\Support\E2E\ShadowDom;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class CodeViewTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    #[TestDox('The tree expands directories on demand')]
    public function testTreeExpandsDirectoriesOnDemand(): void
    {
        $client = $this->browserAtCodeViewReview('');
        $client->waitFor(self::treeItem('src'));
        self::assertSame(['dir='], self::listedDirectories($client));

        self::clickTreeItem($client, 'src');
        $client->waitFor(self::treeItem('src/Billing'));
        self::clickTreeItem($client, 'src/Billing');
        $client->waitFor(self::treeItem(FixtureRepositories::SMALL_FILE));

        self::assertSame(['dir=', 'dir=src', 'dir=src%2FBilling'], self::listedDirectories($client));
        self::clickTreeItem($client, FixtureRepositories::SMALL_FILE);
        $client->waitForElementToContain('[data-testid="file-header"]', FixtureRepositories::SMALL_FILE);
    }

    #[TestDox('Line 1 text is readable from the shadow root')]
    public function testLineOneIsReadableFromTheShadowRoot(): void
    {
        $client = $this->browserAtCodeViewReview('/files/'.FixtureRepositories::SMALL_FILE);

        $text = ShadowDom::waitUntil($client, '[data-line="1"]', static fn (WebDriverElement $line): ?string => '' === ($lineText = $line->getText()) ? null : $lineText);

        self::assertSame('<?php', $text);
    }

    #[TestDox('The view has one line-number column')]
    public function testViewHasOneLineNumberColumn(): void
    {
        $client = $this->browserAtCodeViewReview('/files/'.FixtureRepositories::SMALL_FILE);

        ShadowDom::waitUntil($client, '[data-line="1"]', static fn (): bool => true);

        self::assertSame(1, ShadowDom::count($client, '[data-gutter]'));
        self::assertSame(1, ShadowDom::count($client, '[data-column-number="1"]'));
    }

    private function browserAtCodeViewReview(string $subPath): Client
    {
        $user = $this->user();
        $reviewId = self::startedReview($user, self::connectedRepository($user, $this->localSource(FixtureRepositories::codeView())), 'Code view');
        $client = self::browserSignedInAs($user);
        $client->request('GET', "/reviews/{$reviewId}{$subPath}");

        return $client;
    }

    private static function treeItem(string $path): string
    {
        return "[data-testid=\"file-tree\"] [data-path=\"{$path}\"]";
    }

    private static function clickTreeItem(Client $client, string $path): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::treeItem($path)))->click();
    }

    /**
     * The `dir` query of every V6 request that the page made.
     *
     * @return list<string>
     */
    private static function listedDirectories(Client $client): array
    {
        $directories = $client->executeScript(<<<'JS'
            return performance.getEntriesByType('resource')
                .map((entry) => new URL(entry.name))
                .filter((url) => url.pathname.endsWith('/tree'))
                .map((url) => url.search.match(/dir=[^&]*/)[0]);
            JS);
        self::assertIsArray($directories);

        return array_values(array_map(strval(...), $directories));
    }
}
