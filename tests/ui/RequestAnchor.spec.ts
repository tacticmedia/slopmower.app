import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { ROUND_FQN, SMALL_FILE, SYMBOL_FILE } from './support/files';
import { openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const SNAPSHOT_ROWS = '[data-testid="anchor-snapshot"] tr';

test.describe('RequestAnchor', () => {
    test('A symbol request shows its FQN in the thread and in the request detail', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedSymbol(SYMBOL_FILE, ROUND_FQN, 'Round half to even.');

        await openFile(page, review, SYMBOL_FILE);
        await expect(page.locator('[slot="annotation-17"] [data-testid="thread-symbol"]')).toHaveText(ROUND_FQN);

        await openRequest(page, request.id);
        await expect(page.locator('[data-testid="anchor-symbol"]')).toHaveText(ROUND_FQN);
    });

    test('The snapshot shows its context lines, dimmed', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');

        await openRequest(page, request.id);

        const context = page.locator(`${SNAPSHOT_ROWS}[data-context]`);
        await expect(context).toHaveCount(4);
        expect(await context.evaluateAll((rows) => rows.map((row) => row.getAttribute('data-line')))).toEqual(['6', '7', '8', '13']);
        const color = (line: number): Promise<string> => page.locator(`${SNAPSHOT_ROWS}[data-line="${line}"] td`).last().evaluate((cell) => getComputedStyle(cell).color);
        expect(await color(8)).not.toBe(await color(9));
    });
});
