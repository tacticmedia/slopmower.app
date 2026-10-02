<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\McpHttpClient;
use App\Tests\Support\E2E\TokenSettings;
use Doctrine\DBAL\Connection;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class TokenSettingsTest extends PantherTestCase
{
    use ResetDatabase;
    use TokenSettings;

    private const string TOKEN_NAME = 'claude-code laptop';

    #[TestDox('The secret is shown once and matches the token format')]
    public function testSecretIsShownOnce(): void
    {
        $client = $this->openSettingsAs('jan');

        $secret = $this->createToken($client, self::TOKEN_NAME);

        self::assertMatchesRegularExpression('/^hr_[A-Za-z0-9_-]{43}$/D', $secret);
        $this->closeIssuedToken($client);
        self::assertSame('Active', $this->tokenCell($client, self::TOKEN_NAME, 'State'));
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space()="Create token"]'))->click();
        $client->waitForVisibility('input[name="name"]');
        self::assertStringNotContainsString($secret, $client->getPageSource());
    }

    #[TestDox('A second submit while T2 runs creates no second token')]
    public function testSecondSubmitCreatesNoSecondToken(): void
    {
        $client = $this->openSettingsAs('jan');
        $this->fillCreateDialog($client, self::TOKEN_NAME);

        $client->executeScript("const form = document.querySelector('div[role=\"dialog\"] form'); form.requestSubmit(); setTimeout(() => form.requestSubmit(), 50);");

        $client->waitForVisibility(self::SECRET);
        $this->closeIssuedToken($client);
        self::assertSame('Active', $this->tokenCell($client, self::TOKEN_NAME, 'State'));
        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM api_token'));
    }

    #[TestDox('The page source after a reload does not contain the secret')]
    public function testReloadDropsTheSecret(): void
    {
        $client = $this->openSettingsAs('jan');
        $secret = $this->createToken($client, self::TOKEN_NAME);

        $client->reload();

        self::assertSame('All repositories', $this->tokenCell($client, self::TOKEN_NAME, 'Scope'));
        self::assertStringNotContainsString($secret, $client->getPageSource());
    }

    #[TestDox('MCP initialize works with the new token')]
    public function testMcpInitializeWorksWithTheNewToken(): void
    {
        $client = $this->openSettingsAs('jan');
        $secret = $this->createToken($client, self::TOKEN_NAME);

        $initialized = McpHttpClient::connect($secret)->initialize();

        self::assertIsArray($initialized['serverInfo']);
        self::assertSame('holistic-review', $initialized['serverInfo']['name']);
    }

    #[TestDox('After revoke, the same call gets 401')]
    public function testRevokedTokenGets401(): void
    {
        $client = $this->openSettingsAs('jan');
        $secret = $this->createToken($client, self::TOKEN_NAME);
        $this->closeIssuedToken($client);
        self::assertSame('Active', $this->tokenCell($client, self::TOKEN_NAME, 'State'));
        McpHttpClient::connect($secret)->initialize();

        $client->getWebDriver()->findElement(WebDriverBy::xpath('//tr[th[normalize-space()="'.self::TOKEN_NAME.'"]]//button[normalize-space()="Revoke"]'))->click();
        $client->waitForVisibility('div[role="alertdialog"]');
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//div[@role="alertdialog"]//button[normalize-space()="Revoke"]'))->click();

        $client->waitForElementToContain('//tr[th[normalize-space()="'.self::TOKEN_NAME.'"]]', 'Revoked');
        self::assertSame(401, McpHttpClient::connect($secret)->initializeResponse()->getStatusCode());
    }
}
