import type { Locator, Page } from '@playwright/test';
import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, LARGE_FILE } from './support/files';
import { expect, test } from './support/test';

/** Enough root files to make the tree taller than the window. */
const ROOT_FILES = 60;

/** The padding of the file column at each side. */
const CONTENT_PADDING_PX = 16;

function reviewWithManyRootFiles(api: ConstructorParameters<typeof FakeReview>[0]): FakeReview {
    return new FakeReview(api, { files: { ...codeViewFiles(), ...Object.fromEntries(Array.from({ length: ROOT_FILES }, (_, index) => [`notes-${index}.md`, '# Note\n'])) } });
}

/** The sticky wrapper of the tree pane. */
function pane(page: Page): Locator {
    return page.locator('[data-testid="file-tree"]').locator('xpath=ancestor::*[@data-sticky or contains(@class, "PaneWrapper")][1]');
}

function rect(locator: Locator): Promise<{ top: number; bottom: number; left: number; right: number; height: number; width: number }> {
    return locator.evaluate((element) => {
        const { top, bottom, left, right, height, width } = element.getBoundingClientRect();

        return { top, bottom, left, right, height, width };
    });
}

/** The code view loads in its own chunk, and the page has the height of the code only then. */
async function waitForCodeHeight(page: Page): Promise<void> {
    await expect.poll(() => page.evaluate(() => (document.scrollingElement?.scrollHeight ?? 0) > window.innerHeight * 10)).toBe(true);
}

function scrollWindowTo(page: Page, top: number): Promise<void> {
    return page.evaluate((y) => new Promise<void>((resolve) => {
        window.scrollTo(0, y);
        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
    }), top);
}

test.describe('CodeBrowserLayout', () => {
    test('The code browser spans the window, and the window scrolls the headers away while the tree and the toolbar stick', async ({ page, api }) => {
        await openFile(page, reviewWithManyRootFiles(api), LARGE_FILE);
        const viewportWidth = await page.evaluate(() => window.innerWidth);

        expect((await rect(pane(page))).left).toBe(0);
        expect((await rect(page.locator('[data-testid="code-view"]'))).right).toBeCloseTo(viewportWidth - CONTENT_PADDING_PX, 0);
        await waitForCodeHeight(page);

        await scrollWindowTo(page, 3000);

        await expect(page.getByRole('heading', { level: 1 })).not.toBeInViewport();
        await expect.poll(async () => (await rect(pane(page))).top).toBe(0);
        expect((await rect(pane(page))).height).toBe(await page.evaluate(() => window.innerHeight));
        const stuck = page.locator('[data-testid="stuck-header"]');
        await expect(stuck).toBeVisible();
        expect((await rect(stuck)).top).toBe(0);
        expect((await rect(page.locator('[data-testid="file-toolbar"]'))).top).toBeCloseTo((await rect(stuck)).bottom, 0);
        const tree = page.locator('[data-testid="file-tree"]').locator('xpath=ancestor::*[@data-resizable][1]');
        expect(await tree.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);

        await stuck.getByRole('button', { name: 'Top' }).click();

        await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
        await expect(stuck).toHaveCount(0);
        await expect(page.getByRole('heading', { level: 1 })).toBeInViewport();
    });

    test('The pane splitter resizes the tree with the keyboard, and the width stays after a reload', async ({ page, api }) => {
        await openFile(page, new FakeReview(api), LARGE_FILE);
        const before = (await rect(pane(page))).width;

        await page.getByRole('slider', { name: 'Draggable pane splitter' }).focus();
        for (let step = 0; step < 5; step++) {
            await page.keyboard.press('ArrowRight');
        }

        await expect.poll(async () => (await rect(pane(page))).width).toBeGreaterThan(before);
        const resized = (await rect(pane(page))).width;
        await page.reload();
        await expect.poll(async () => (await rect(pane(page))).width).toBe(resized);
    });

    test('Collapse file tree and Expand file tree hide and show the tree, and the state stays after a reload', async ({ page, api }) => {
        await openFile(page, new FakeReview(api), LARGE_FILE);
        const tree = page.locator('[data-testid="file-tree"]');

        await page.getByRole('button', { name: 'Collapse file tree' }).click();

        await expect(tree).toBeHidden();
        await page.reload();
        await expect(page.locator('[data-testid="file-header"]')).toContainText(LARGE_FILE);
        await expect(tree).toBeHidden();
        await page.getByRole('button', { name: 'Expand file tree' }).first().click();
        await expect(tree).toBeVisible();
    });

    test('At 700 px width the tree stacks above the file and scrolls away with the page', async ({ page, api }) => {
        await page.setViewportSize({ width: 700, height: 720 });
        await openFile(page, reviewWithManyRootFiles(api), LARGE_FILE);

        await waitForCodeHeight(page);
        expect((await rect(pane(page))).bottom).toBeLessThanOrEqual((await rect(page.locator('[data-testid="code-view"]'))).top);
        await scrollWindowTo(page, 3000);

        expect((await rect(pane(page))).bottom).toBeLessThan(0);
    });
});
