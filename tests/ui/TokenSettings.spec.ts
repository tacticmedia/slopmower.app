import type { Page } from '@playwright/test';
import { FakeRepositories } from './support/fakeRepositories';
import { FakeTokens } from './support/fakeTokens';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const TOKEN_NAME = 'claude-code laptop';
const SECRET = '[data-testid="token-secret"]';
const TOKENS_TABLE = 'table[aria-labelledby="api-tokens"]';

async function openSettings(page: Page, api: MockApi): Promise<FakeTokens> {
    const tokens = new FakeTokens(api, new FakeRepositories(api));
    await page.goto('/settings');
    await expect(page.locator('#api-tokens')).toBeVisible();

    return tokens;
}

async function fillCreateDialog(page: Page, name: string): Promise<void> {
    await page.getByRole('button', { name: 'Create token' }).click();
    await page.locator('input[name="name"]').fill(name);
}

/**
 * Fills and submits the create dialog, and leaves the one-time display open.
 *
 * @returns The secret that the dialog shows
 */
async function createToken(page: Page, name: string): Promise<string> {
    await fillCreateDialog(page, name);
    await page.getByRole('dialog').getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.locator(SECRET)).toBeVisible();

    return (await page.locator(SECRET).textContent()) ?? '';
}

async function closeIssuedToken(page: Page): Promise<void> {
    await page.getByRole('dialog').getByRole('button', { name: 'Done' }).click();
    await expect(page.locator(SECRET)).toHaveCount(0);
}

function tokenRow(page: Page, name: string) {
    return page.locator(`${TOKENS_TABLE} tbody tr`).filter({ has: page.locator('th').getByText(name, { exact: true }) });
}

/** Waits until the token row exists, then reads the cell under the column header. */
async function tokenCell(page: Page, name: string, column: string): Promise<string | null> {
    const row = tokenRow(page, name);
    await expect(row).toHaveCount(1);
    const headers = await page.locator(`${TOKENS_TABLE} thead th`).allTextContents();
    expect(headers).toContain(column);

    return row.locator('th, td').nth(headers.indexOf(column)).textContent();
}

test.describe('TokenSettings', () => {
    test('The secret is shown once and matches the token format', async ({ page, api }) => {
        await openSettings(page, api);

        const secret = await createToken(page, TOKEN_NAME);

        expect(secret).toMatch(/^hr_[A-Za-z0-9_-]{43}$/);
        await closeIssuedToken(page);
        expect(await tokenCell(page, TOKEN_NAME, 'State')).toBe('Active');
        await page.getByRole('button', { name: 'Create token' }).click();
        await expect(page.locator('input[name="name"]')).toBeVisible();
        expect(await page.content()).not.toContain(secret);
        expect(api.bodies('POST', '/api/v1/tokens')).toEqual([{ name: TOKEN_NAME, repositoryId: null, expiresAt: null }]);
    });

    test('A second submit while T2 runs creates no second token', async ({ page, api }) => {
        const tokens = await openSettings(page, api);
        let answerT2 = (): void => undefined;
        tokens.responseGate = new Promise<void>((resolve) => {
            answerT2 = resolve;
        });
        await fillCreateDialog(page, TOKEN_NAME);
        const submit = () => page.evaluate(() => document.querySelector<HTMLFormElement>('div[role="dialog"] form')?.requestSubmit());

        await submit();
        await expect.poll(() => api.callsTo('POST', '/api/v1/tokens').length).toBe(1);
        await submit();
        answerT2();

        await expect(page.locator(SECRET)).toBeVisible();
        await closeIssuedToken(page);
        expect(await tokenCell(page, TOKEN_NAME, 'State')).toBe('Active');
        expect(api.callsTo('POST', '/api/v1/tokens')).toHaveLength(1);
    });

    test('The page source after a reload does not contain the secret', async ({ page, api }) => {
        await openSettings(page, api);
        const secret = await createToken(page, TOKEN_NAME);

        await page.reload();

        expect(await tokenCell(page, TOKEN_NAME, 'Scope')).toBe('All repositories');
        expect(await page.content()).not.toContain(secret);
    });

    test('MCP initialize works with the new token', async ({ page, api }) => {
        const tokens = await openSettings(page, api);

        const secret = await createToken(page, TOKEN_NAME);

        const [issued] = tokens.tokens;
        expect(secret).toBe(tokens.secrets.get(issued?.id ?? ''));
        await expect(page.getByRole('dialog')).toContainText(api.me.links.mcpUrl);
    });

    test('After revoke, the same call gets 401', async ({ page, api }) => {
        const tokens = await openSettings(page, api);
        await createToken(page, TOKEN_NAME);
        await closeIssuedToken(page);
        expect(await tokenCell(page, TOKEN_NAME, 'State')).toBe('Active');

        await tokenRow(page, TOKEN_NAME).getByRole('button', { name: 'Revoke' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Revoke' }).click();

        await expect(tokenRow(page, TOKEN_NAME)).toContainText('Revoked');
        expect(api.callsTo('DELETE', '/api/v1/tokens/:tokenId').map(({ params }) => params.tokenId)).toEqual([tokens.tokens[0]?.id]);
    });
});
