import type { IssueDetail, IssueEntry } from '../../assets/api/types/issue';
import { issueDetail, newId, now } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { expect, test } from './support/test';

const NOTES = 'Rounding is in `InvoiceTotals::total()`.';

/** `BILL-12` of `issue.detail.json` with a current version of the notes. */
function withNotes(): IssueDetail {
    const detail = issueDetail({ notes: NOTES });

    return { ...detail, thread: [...detail.thread, { ...(detail.thread[0] as IssueEntry), id: newId(), createdAt: now(), section: 'notes', body: NOTES }] };
}

test.describe('Issue notes', () => {
    test('The notes are closed in the timeline and open with "Revise" on a click or with their link in the row "Current", and an issue without notes has no "Notes" there', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ key: 'BILL-13' }));
        issues.add(withNotes());

        await page.goto('/issues/BILL-13');
        await expect(page.getByTestId('issue-detail')).toBeVisible();
        await expect(page.getByTestId('issue-current')).not.toContainText('Notes');

        await page.goto('/issues/BILL-12');
        const notes = page.getByTestId('notes-entry');
        await expect(page.getByTestId('issue-current').getByRole('link', { name: 'Notes' })).toBeVisible();
        await expect(notes).toContainText('changed the notes');
        await expect(notes.locator('code')).toBeHidden();

        await notes.locator('summary').click();

        await expect(notes.locator('code')).toHaveText('InvoiceTotals::total()');
        await expect(notes.getByRole('button', { name: 'Revise Notes' })).toBeVisible();

        await notes.locator('summary').click();
        await expect(notes.locator('code')).toBeHidden();
        await page.getByTestId('issue-current').getByRole('link', { name: 'Notes' }).click();

        await expect(notes.locator('code')).toBeVisible();
    });
});
