<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class ShellTest extends PantherTestCase
{
    use ResetDatabase;

    private const string CURRENT_USER = '[data-testid="current-user"]';

    private const string MARKER = 'window.shellMarker';

    #[TestDox('The header shows the login of the signed-in user')]
    public function testHeaderShowsTheLogin(): void
    {
        $client = $this->openShellAs('jan');

        self::assertSame('jan', $client->getCrawler()->filter('header '.self::CURRENT_USER)->text());
    }

    #[TestDox('Client-side navigation keeps a window marker')]
    public function testClientSideNavigationKeepsTheWindow(): void
    {
        $client = $this->openShellAs('jan');
        $client->executeScript(self::MARKER." = 'kept';");

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('nav[aria-label="Main"] a[href="/repositories"]'))->click();

        $client->wait()->until(WebDriverExpectedCondition::urlIs('http://localhost:8081/repositories'));
        self::assertSame('kept', $client->executeScript('return '.self::MARKER.';'));
    }

    #[TestDox('Client-side navigation to an unknown path shows the error boundary')]
    public function testUnknownPathShowsTheErrorBoundary(): void
    {
        $client = $this->openShellAs('jan');
        $client->executeScript(self::MARKER." = 'kept';");

        $client->executeScript("history.pushState(null, '', '/no-such-page'); dispatchEvent(new PopStateEvent('popstate'));");

        $client->waitForElementToContain('[data-testid="route-error"] h1', 'Page not found');
        self::assertSame('kept', $client->executeScript('return '.self::MARKER.';'));
    }

    #[TestDox('Sign out ends the session, and /api/v1/me then answers 401')]
    public function testSignOutEndsTheSession(): void
    {
        $client = $this->openShellAs('jan');

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('header form[action="/sign-out"] button'))->click();

        $client->wait()->until(WebDriverExpectedCondition::urlIs('http://localhost:8081/login'));
        $status = $client->executeAsyncScript(
            "const done = arguments[arguments.length - 1]; fetch('/api/v1/me').then((response) => done(response.status));",
        );
        self::assertSame(401, $status);
    }

    private function openShellAs(string $login): Client
    {
        $client = static::createPantherClient();
        $client->request('GET', '/_e2e/sign-in/'.$login);
        $client->request('GET', '/');
        $client->waitForElementToContain(self::CURRENT_USER, $login);

        return $client;
    }
}
