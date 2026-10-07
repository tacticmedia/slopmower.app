import type { Page } from '@playwright/test';
import type { Declaration } from '../../assets/api/types/declaration';
import { declarationList } from './support/builders';
import { CODE_HOST, openFile, tabUntil, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, NESTED_FILE } from './support/files';
import { json, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const CALLER = 'src/Billing/Checkout.php';

const CALLER_TEXT = `<?php

namespace App\\Billing;

final class Checkout
{
    public function total(int $amount): Money
    {
        return new Money($amount);
    }
}
`;

const CLICKED_LINE = 9;

/** The column of "Money" on line 9. */
const CLICKED_COLUMN = 20;

const MONEY_FILE = 'src/Billing/Money.php';

/** The class declaration is on line 8, below its doc comment. */
const MONEY_TEXT = `<?php

namespace App\\Billing;

/**
 * An amount in cents.
 */
final class Money
{
    public function __construct(public int $cents)
    {
    }
}
`;

const MONEY: Declaration = { path: MONEY_FILE, fqn: 'App\\Billing\\Money', kind: 'class', range: { startLine: 5, endLine: 13 }, declarationStartLine: 8 };

const DECLARATIONS = '/api/v1/reviews/:reviewId/declarations';

function reviewWithCaller(api: MockApi, targets: Declaration[]): FakeReview {
    const review = new FakeReview(api, { files: { ...codeViewFiles(), [CALLER]: CALLER_TEXT, [MONEY_FILE]: MONEY_TEXT } });
    api.on('GET', DECLARATIONS, () => json('lists.schema.json#/$defs/DeclarationList', declarationList(review.id, targets)));

    return review;
}

async function cmdClickMoney(page: Page): Promise<void> {
    await waitForHighlighting(page, CLICKED_LINE);
    await page.locator(`${CODE_HOST} [data-line="${CLICKED_LINE}"] [data-char]`).filter({ hasText: /^Money$/ }).click({ modifiers: ['ControlOrMeta'] });
}

function location(page: Page): string {
    const url = new URL(page.url());

    return `${decodeURIComponent(url.pathname)}${url.hash}`;
}

function selectedLines(page: Page): Promise<string[]> {
    return page.locator(`${CODE_HOST} [data-line][data-selected-line]`).evaluateAll((lines) => lines.map((line) => line.getAttribute('data-line') ?? ''));
}

test.describe('CodeNavigation', () => {
    test('Cmd-click on a class name opens its declaration line, and Back returns to the previous line', async ({ page, api }) => {
        const review = reviewWithCaller(api, [MONEY]);
        await openFile(page, review, CALLER);

        await cmdClickMoney(page);

        await expect.poll(() => location(page)).toBe(review.fileUrl(MONEY_FILE, '#L8'));
        await expect.poll(() => selectedLines(page)).toEqual(['8']);
        expect(api.callsTo('GET', DECLARATIONS).map(({ query }) => Object.fromEntries(query))).toEqual([{ path: CALLER, line: String(CLICKED_LINE), column: String(CLICKED_COLUMN) }]);

        await page.goBack();

        await expect.poll(() => location(page)).toBe(review.fileUrl(CALLER, `#L${CLICKED_LINE}`));
        await expect.poll(() => selectedLines(page)).toEqual([String(CLICKED_LINE)]);
    });

    test('By keyboard only, a typed class name in Go to declaration opens its declaration', async ({ page, api }) => {
        const review = reviewWithCaller(api, [MONEY]);
        await openFile(page, review, CALLER);

        await tabUntil(page, () => page.evaluate(() => 'Go to declaration' === document.activeElement?.getAttribute('aria-label')), 'the "Go to declaration" field');
        await page.keyboard.type('Money');
        await page.keyboard.press('Enter');

        await expect.poll(() => location(page)).toBe(review.fileUrl(MONEY_FILE, '#L8'));
        await expect(page.locator('[data-testid="file-header"]')).toContainText(MONEY_FILE);
        expect(api.callsTo('GET', DECLARATIONS).map(({ query }) => Object.fromEntries(query))).toEqual([{ path: CALLER, name: 'Money' }]);
    });

    test('The tree expands the collapsed directory of the declaration', async ({ page, api }) => {
        const review = reviewWithCaller(api, [{ path: NESTED_FILE, fqn: 'App\\Billing\\Tax\\TaxRate', kind: 'class', range: { startLine: 5, endLine: 7 }, declarationStartLine: 5 }]);
        await openFile(page, review, CALLER);

        await cmdClickMoney(page);

        await expect(page.locator(`[data-testid="file-tree"] [data-path="${NESTED_FILE}"]`)).toHaveAttribute('aria-current', 'true');
    });

    test('A name without a declaration shows the message and stays on the file', async ({ page, api }) => {
        const review = reviewWithCaller(api, []);
        await openFile(page, review, CALLER);

        await cmdClickMoney(page);

        await expect(page.locator('[data-testid="file-header"]').getByRole('status').filter({ hasText: 'No declaration in this commit' })).toBeVisible();
        expect(location(page)).toBe(review.fileUrl(CALLER));
    });
});
