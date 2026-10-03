<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

/**
 * Drives the settings screen in a {@see FullStackTestCase}.
 */
trait TokenSettings
{
    private const string SECRET = '[data-testid="token-secret"]';
    private const string TOKENS_TABLE = 'table[aria-labelledby="api-tokens"]';

    private function openSettingsAs(string $login): void
    {
        $this->signInAs($login);
        $this->visit('/settings');
        $this->expect($this->page->locator('#api-tokens'))->toBeVisible();
    }

    /**
     * Fills and submits the create dialog, and leaves the one-time display open.
     *
     * @param string|null $expiresOn Y-m-d in the browser's time zone
     *
     * @return string The secret that the dialog shows
     */
    private function createToken(string $name, ?string $repositoryName = null, ?string $expiresOn = null): string
    {
        $this->page->getByRole('button', ['name' => 'Create token', 'exact' => true])->click();
        $dialog = $this->page->getByRole('dialog');
        $dialog->locator('input[name="name"]')->fill($name);

        if (null !== $repositoryName) {
            $dialog->locator('select[name="repositoryId"]')->selectOption(['label' => $repositoryName]);
        }

        if (null !== $expiresOn) {
            $dialog->locator('input[name="expiresOn"]')->fill($expiresOn);
        }

        $dialog->getByRole('button', ['name' => 'Create', 'exact' => true])->click();
        $secret = $this->page->locator(self::SECRET);
        $this->expect($secret)->toBeVisible();

        return (string) $secret->textContent();
    }

    private function closeIssuedToken(): void
    {
        $this->page->getByRole('dialog')->getByRole('button', ['name' => 'Done', 'exact' => true])->click();
        $this->expect($this->page->locator(self::SECRET))->toHaveCount(0);
    }

    /**
     * Waits until the token row exists, then reads the cell under the column header.
     */
    private function tokenCell(string $tokenName, string $column): string
    {
        $row = $this->page->locator('xpath=//table[@aria-labelledby="api-tokens"]/tbody/tr[th[normalize-space()="'.$tokenName.'"]]');
        $this->expect($row)->toHaveCount(1);
        // playwright-php 1.5.0 sends allTextContents() to its server, which has no such action.
        $headers = $this->page->locator(self::TOKENS_TABLE.' thead th')->evaluateAll('(headers) => headers.map((header) => header.textContent)');
        self::assertIsArray($headers);
        $index = array_search($column, $headers, true);
        self::assertIsInt($index, "No column {$column} in ".implode(', ', $headers));

        return (string) $row->locator('th, td')->nth($index)->textContent();
    }
}
