<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class RepositoriesTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string CANDIDATES = '[data-testid="local-candidates"]';

    private const string CONNECTED = '[data-testid="connected-repositories"]';

    #[TestDox('A repository that the test created is listed as a candidate')]
    public function testCreatedRepositoryIsACandidate(): void
    {
        $directory = $this->localSource();
        $client = self::browserSignedInAs($this->user());

        $client->request('GET', '/repositories');

        $client->waitFor(self::connectButton($directory));
        self::assertSelectorTextContains(self::CANDIDATES.' table', $directory);
    }

    #[TestDox('Connect adds it to the connected repositories')]
    public function testConnectAddsTheRepository(): void
    {
        $directory = $this->localSource();
        $client = self::browserSignedInAs($this->user());
        $client->request('GET', '/repositories');
        $client->waitFor(self::connectButton($directory));

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::connectButton($directory)))->click();

        $client->waitForElementToContain(self::CONNECTED.' table', $directory);
        self::assertSelectorTextContains(self::CONNECTED.' [data-testid="sync-state"]', 'Ready');
        self::assertSelectorTextContains(self::CANDIDATES.' table', 'Connected');
    }

    #[TestDox('A notice shows when local repositories are disabled')]
    public function testNoticeWhenLocalRepositoriesAreDisabled(): void
    {
        $this->disableLocalRepositories();
        $client = self::browserSignedInAs($this->user());

        $client->request('GET', '/repositories');

        $client->waitFor('[data-testid="local-repositories-off"]');
        self::assertSelectorNotExists(self::CANDIDATES.' table');
    }

    private static function connectButton(string $directory): string
    {
        return self::CANDIDATES." button[aria-label=\"Connect {$directory}\"]";
    }
}
