import { expect, type Locator, mergeTests } from '@playwright/test';
import { test as capture } from '@tacticmedia/qa-capture/playwright';
import { test as ui } from '../../ui/support/test';

export { expect } from '@playwright/test';

/** Captures the screen once `content` is visible, no spinner turns, no write is pending, and every Markdown body is rendered. */
type CaptureScreen = (label: string, content: Locator) => Promise<void>;

export const test = mergeTests(ui, capture).extend<{ captureScreen: CaptureScreen }>({
    captureScreen: async ({ page, qaScreenshots }, use) => {
        await use(async (label, content) => {
            await expect(content).toBeVisible();
            await expect(page.locator('[class*="SpinnerAnimation"]').filter({ visible: true })).toHaveCount(0);
            await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
            await expect(page.locator('[data-markdown-pending]')).toHaveCount(0);
            await page.evaluate(() => document.fonts.ready);
            await qaScreenshots.capture(label);
        });
    },
});
