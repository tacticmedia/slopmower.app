<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use Doctrine\DBAL\Connection;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

/**
 * The e2e site runs the purge job during A2, through `sync://`.
 */
final class DeleteAccountFlowTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string OPEN_DELETE_DIALOG = '//main//button[normalize-space()="Delete account"]';

    #[TestDox('Typing the login and confirming signs the user out')]
    public function testTypingTheLoginSignsTheUserOut(): void
    {
        $user = $this->user();
        self::connectedRepository($user, $this->localSource());
        $client = self::browserSignedInAs($user);
        $client->request('GET', '/settings');
        $client->waitFor(self::OPEN_DELETE_DIALOG);

        $client->getWebDriver()->findElement(WebDriverBy::xpath(self::OPEN_DELETE_DIALOG))->click();
        $client->waitForVisibility('[role="dialog"] input');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[role="dialog"] input'))->sendKeys('jan');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[role="dialog"] button[type="submit"]'))->click();

        $client->wait()->until(WebDriverExpectedCondition::urlMatches('#/login$#'));
        $client->waitForElementToContain('h1', 'Sign in to Holistic Review');
        $client->request('GET', '/settings');
        $client->wait()->until(WebDriverExpectedCondition::urlMatches('#/login$#'));
        self::assertSame(
            ['app_user' => 0, 'signed_in_sessions' => 0],
            self::getContainer()->get(Connection::class)->fetchAssociative(
                "SELECT (SELECT count(*) FROM app_user) AS app_user, (SELECT count(*) FROM sessions WHERE position('_security_web'::bytea IN sess_data) > 0) AS signed_in_sessions",
            ),
        );
    }
}
