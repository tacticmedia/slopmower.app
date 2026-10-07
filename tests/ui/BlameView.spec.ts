import type { Locator, Page } from '@playwright/test';
import type { BlameCommit } from '../../assets/api/types/blame';
import { CODE_HOST, lineNumber, location, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const MAXIMUM_RENDERED_ROWS = 300;

const DAY_MS = 86_400_000;

function commit(sha: string, authorName: string, daysAgo: number, summary: string): BlameCommit {
    return { sha, authorName, authoredAt: new Date(Date.now() - daysAgo * DAY_MS).toISOString().replace(/\.\d{3}Z$/, 'Z'), summary };
}

const ADA = commit('1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d', 'Ada Lovelace', 500, 'Add invoice totals');

const GRACE = commit('2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e', 'Grace Hopper', 3, 'Round tax per line');

function blameUrl(review: FakeReview, path: string, hash = ''): string {
    return `/reviews/${review.id}/blame/${path}${hash}`;
}

function block(page: Page, lines: string): Locator {
    return page.locator(`[data-testid="blame-block"][data-lines="${lines}"]`);
}

/** The top of the first row and the bottom of the last row of a block, and the block itself, in window coordinates. */
async function spans(page: Page, lines: string): Promise<{ block: [number, number]; rows: [number, number] }> {
    const [first, last] = lines.split('-');
    const blockBox = await block(page, lines).evaluate((element) => element.getBoundingClientRect().toJSON() as DOMRect);
    const firstRow = await page.locator(`${CODE_HOST} [data-line="${first}"]`).evaluate((element) => element.getBoundingClientRect().top);
    const lastRow = await page.locator(`${CODE_HOST} [data-line="${last}"]`).evaluate((element) => element.getBoundingClientRect().bottom);

    return { block: [blockBox.top, blockBox.bottom], rows: [firstRow, lastRow] };
}

async function expectAligned(page: Page, lines: string): Promise<void> {
    await expect.poll(async () => {
        const { block: [top, bottom], rows: [rowTop, rowBottom] } = await spans(page, lines);

        return Math.abs(top - rowTop) <= 1 && Math.abs(bottom - rowBottom) <= 1;
    }).toBe(true);
}

test.describe('BlameView', () => {
    test('Blame in the toolbar opens the blame of the file with the line hash, and Code returns', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE, '#L5');
        const fileView = page.locator('[aria-label="File view"]');

        await fileView.getByRole('button', { name: 'Blame' }).click();

        await expect.poll(() => location(page)).toBe(blameUrl(review, SMALL_FILE, '#L5'));
        await expect(page.locator('[data-testid="blame-column"]')).toBeVisible();
        await fileView.getByRole('button', { name: 'Code' }).click();
        await expect.poll(() => location(page)).toBe(review.fileUrl(SMALL_FILE, '#L5'));
        await expect(page.locator('[data-testid="blame-column"]')).toHaveCount(0);
    });

    test('Each range has one block beside its lines, with the date, the author, the summary, and the age colour', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.blames.set(SMALL_FILE, {
            ignoreRevsFile: false,
            ranges: [
                { startLine: 1, endLine: 6, commitSha: ADA.sha },
                { startLine: 7, endLine: 7, commitSha: GRACE.sha },
                { startLine: 8, endLine: 13, commitSha: ADA.sha },
            ],
            commits: [ADA, GRACE],
        });

        await page.goto(blameUrl(review, SMALL_FILE));

        await expect(page.locator('[data-testid="blame-block"]')).toHaveCount(3);
        await expect(block(page, '7-7')).toContainText('Grace Hopper');
        await expect(block(page, '7-7')).toContainText('Round tax per line');
        await expect(block(page, '7-7').locator('relative-time')).toHaveAttribute('datetime', new Date(GRACE.authoredAt).toISOString());
        await expect(block(page, '7-7')).toHaveAttribute('data-age-scale', '9');
        await expect(block(page, '1-6')).toHaveAttribute('data-age-scale', '0');
        for (const lines of ['1-6', '7-7', '8-13']) {
            await expectAligned(page, lines);
        }
    });

    test('A block far down the file aligns after #L3000, and fewer than 300 rows render', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.blames.set(LARGE_FILE, {
            ignoreRevsFile: false,
            ranges: [
                { startLine: 1, endLine: 2999, commitSha: ADA.sha },
                { startLine: 3000, endLine: 3000, commitSha: GRACE.sha },
                { startLine: 3001, endLine: 3500, commitSha: ADA.sha },
            ],
            commits: [ADA, GRACE],
        });

        await page.goto(blameUrl(review, LARGE_FILE, '#L3000'));

        await expect(page.locator(`${CODE_HOST} [data-line="3000"][data-selected-line]`)).toContainText('LINE_3000');
        await expectAligned(page, '3000-3000');
        await expect(block(page, '3000-3000')).toBeInViewport();
        expect(await page.locator(`${CODE_HOST} [data-line]`).count()).toBeLessThan(MAXIMUM_RENDERED_ROWS);
    });

    test('The legend shows, and the banner shows only when the review ignores revisions', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(blameUrl(review, SMALL_FILE));

        await expect(page.locator('[data-testid="blame-legend"]')).toHaveText('OlderNewer');
        await expect(page.locator('[data-testid="ignore-revs"]')).toHaveCount(0);

        review.blames.set(SMALL_FILE, { ignoreRevsFile: true, ranges: [{ startLine: 1, endLine: 13, commitSha: ADA.sha }], commits: [ADA] });
        await page.reload();
        await expect(page.locator('[data-testid="ignore-revs"]')).toHaveText('Ignoring revisions in .git-blame-ignore-revs.');
    });

    test('The blame view has no comment commands, no line wrap, and no gutter +', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(blameUrl(review, SMALL_FILE));
        await expect(page.locator('[data-testid="blame-block"]')).toHaveCount(1);
        const toolbar = page.locator('[data-testid="file-toolbar"]');

        for (const name of ['Comment on lines', 'Symbols', 'Comment on file', 'Wrap lines']) {
            await expect(toolbar.getByRole('button', { name, exact: true })).toHaveCount(0);
        }
        await lineNumber(page, 5).hover();
        await expect(page.locator(`${CODE_HOST} [data-utility-button]`)).toHaveCount(0);
        await expect(toolbar.getByRole('link', { name: 'Raw', exact: true })).toBeVisible();
    });
});
