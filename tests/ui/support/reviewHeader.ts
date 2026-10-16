import type { Page } from '@playwright/test';

export function reviewActions(page: Page) {
    return page.locator('header').getByRole('button', { name: 'Review actions', exact: true });
}

/** Opens "Review actions" and clicks the item. */
export async function chooseReviewAction(page: Page, name: string): Promise<void> {
    await reviewActions(page).click();
    await page.getByRole('menuitem', { name, exact: true }).click();
}
