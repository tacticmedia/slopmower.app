import { expect, type Page } from '@playwright/test';

export const DETAIL = '[data-testid="request-detail"]';

export const DETAIL_STATUS = `${DETAIL} header [data-testid="status-label"]`;

export const DETAIL_NEXT_STEP = `${DETAIL} [data-testid="next-step"]`;

export const DETAIL_SIDEBAR = `${DETAIL} [data-testid="request-sidebar"]`;

export const PLAN_STATE = `${DETAIL_SIDEBAR} [data-testid="plan-state"]`;

export const DETAIL_THREAD = `${DETAIL} [data-testid="thread"]`;

export const REQUEST_ROWS = '[data-testid="request-list"] tbody tr';

export const OUTDATED = '[data-testid="outdated-label"]';

export async function openRequest(page: Page, requestId: string): Promise<void> {
    await page.goto(`/requests/${requestId}`);
    await expect(page.locator(`${DETAIL}[data-request-id="${requestId}"] [data-testid="thread-comment"]`).first()).toBeVisible();
}

export async function clickRequestButton(page: Page, label: string): Promise<void> {
    await page.locator('[data-testid="request-actions"]').getByRole('button', { name: label, exact: true }).click();
}

/** Types the note into the open dialog and clicks its submit button. */
export async function submitNoteDialog(page: Page, note: string, submitLabel: string): Promise<void> {
    const dialog = page.getByRole('dialog');
    await dialog.locator('textarea').fill(note);
    await dialog.getByRole('button', { name: submitLabel, exact: true }).click();
}
