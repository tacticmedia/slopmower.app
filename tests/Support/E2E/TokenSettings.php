<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Drives the settings screen in a PantherTestCase and calls the e2e site with a token from the PHPUnit process.
 */
trait TokenSettings
{
    private const string DIALOG = '//div[@role="dialog"]';

    private const string SECRET = '[data-testid="token-secret"]';

    private function openSettingsAs(string $login): Client
    {
        $client = static::createPantherClient();
        $client->request('GET', '/_e2e/sign-in/'.$login);
        $client->request('GET', '/settings');
        $client->waitFor('#api-tokens');

        return $client;
    }

    /**
     * Fills and submits the create dialog, and leaves the one-time display open.
     *
     * @param string|null $expiresOn Y-m-d in the browser's time zone
     *
     * @return string The secret that the dialog shows
     */
    private function createToken(Client $client, string $name, ?string $repositoryName = null, ?string $expiresOn = null): string
    {
        $this->fillCreateDialog($client, $name, $repositoryName, $expiresOn);
        $client->getWebDriver()->findElement(WebDriverBy::xpath(self::DIALOG.'//button[normalize-space()="Create"]'))->click();

        return $client->waitForVisibility(self::SECRET)->filter(self::SECRET)->text();
    }

    private function fillCreateDialog(Client $client, string $name, ?string $repositoryName = null, ?string $expiresOn = null): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space()="Create token"]'))->click();
        $client->waitForVisibility('input[name="name"]');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('input[name="name"]'))->sendKeys($name);

        if (null !== $repositoryName) {
            new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::cssSelector('select[name="repositoryId"]')))->selectByVisibleText($repositoryName);
        }

        if (null !== $expiresOn) {
            $client->executeScript('document.querySelector(\'input[name="expiresOn"]\').value = arguments[0];', [$expiresOn]);
        }
    }

    private function closeIssuedToken(Client $client): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::xpath(self::DIALOG.'//button[normalize-space()="Done"]'))->click();
        $client->waitForInvisibility(self::SECRET);
    }

    /**
     * Waits until the token row exists, then reads the cell under the column header.
     */
    private function tokenCell(Client $client, string $tokenName, string $column): string
    {
        $row = '//table[@aria-labelledby="api-tokens"]/tbody/tr[th[normalize-space()="'.$tokenName.'"]]';
        $crawler = $client->waitFor($row);
        $headers = $crawler->filterXPath('//table[@aria-labelledby="api-tokens"]/thead/tr/th')->each(static fn ($header): string => $header->text());
        $index = array_search($column, $headers, true);
        self::assertIsInt($index, "No column {$column} in ".implode(', ', $headers));

        return $crawler->filterXPath($row)->children()->eq($index)->text();
    }

    private static function bearerGet(#[\SensitiveParameter] string $secret, string $path): ResponseInterface
    {
        $baseUri = $_SERVER['PANTHER_EXTERNAL_BASE_URI'];
        \assert(\is_string($baseUri));

        return HttpClient::create()->request('GET', $baseUri.$path, ['headers' => ['Authorization' => "Bearer {$secret}"]]);
    }
}
