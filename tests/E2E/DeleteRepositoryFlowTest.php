<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class DeleteRepositoryFlowTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string REPOSITORY_DATA = 'table[aria-labelledby="repository-data"]';

    #[TestDox('Typing the repository name and confirming removes the repository from the list')]
    public function testTypingTheNameRemovesTheRepository(): void
    {
        $user = $this->user();
        $kept = $this->localSource();
        $deleted = $this->localSource();
        self::connectedRepository($user, $kept);
        self::connectedRepository($user, $deleted);
        $client = self::browserSignedInAs($user);
        $client->request('GET', '/settings');
        $client->waitForElementToContain(self::REPOSITORY_DATA, $deleted);

        $client->getWebDriver()->findElement(WebDriverBy::xpath("//table[@aria-labelledby='repository-data']//tr[*[normalize-space()='{$deleted}']]//button[normalize-space()='Delete data']"))->click();
        $client->waitForVisibility('[role="dialog"] input');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[role="dialog"] input'))->sendKeys($deleted);
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[role="dialog"] button[type="submit"]'))->click();

        $client->waitForElementToNotContain(self::REPOSITORY_DATA, $deleted);
        self::assertSelectorTextContains(self::REPOSITORY_DATA, $kept);
        $client->request('GET', '/repositories');
        $client->waitForElementToContain('[data-testid="connected-repositories"] table', $kept);
        self::assertSelectorTextNotContains('[data-testid="connected-repositories"] table', $deleted);
    }
}
