import { readFile } from 'node:fs/promises';
import type { Page } from '@playwright/test';
import type { ProposeSolutionInput } from '../../assets/api/types/inputs';
import { fixture } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { DETAIL_THREAD, openRequest, PLAN_STATE, submitNoteDialog } from './support/requestScreens';
import { expect, test } from './support/test';

/** The plan and the diff of two files of `inputs.propose-solution.json`. */
const { plan: PLAN, diff: DIFF } = fixture<Required<ProposeSolutionInput>>('inputs.propose-solution.json');

const DIFF_LINE = 'diffs-container [data-line-type]';

/** A request with "Ask for a plan first" that the agent claimed. */
function claimedRequest(review: FakeReview): string {
    const request = review.seedGeneral('Add a CSV export of the invoice totals.', true);
    review.agentClaims(request.id);

    return request.id;
}

function proposal(page: Page, eventId: string) {
    return page.locator(`${DETAIL_THREAD} [data-testid="proposal"][data-event-id="${eventId}"]`);
}

test.describe('Proposal', () => {
    test('The proposed diff renders one diff per file', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN, DIFF);

        await openRequest(page, requestId);

        const files = proposal(page, eventId).locator('[data-testid="proposal-diff-file"]');
        await expect(files).toHaveCount(2);
        await expect(files.nth(0)).toHaveAttribute('data-path', 'src/Billing/InvoiceTotals.php');
        await expect(files.nth(1)).toHaveAttribute('data-path', 'src/Billing/InvoiceTotalsCsv.php');
        for (const index of [0, 1]) {
            await expect(files.nth(index).locator(DIFF_LINE).first()).toBeAttached();
        }
        await expect(page.locator(PLAN_STATE)).toHaveText('Waiting for your approval');
    });

    test('A proposed diff that starts after line 1 has no expand control', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN, DIFF);

        await openRequest(page, requestId);

        const file = proposal(page, eventId).locator('[data-testid="proposal-diff-file"][data-path="src/Billing/InvoiceTotals.php"]');
        await expect(file.locator(DIFF_LINE).first()).toBeAttached();
        await expect(file.locator('[data-separator]').first()).toHaveText('@@ -48,5 +48,10 @@');
        await expect(file.locator('[data-expand-button]')).toHaveCount(0);
        await expect(file.locator('[data-unmodified-lines]'), 'No "N unmodified lines" row offers the lines above.').toHaveCount(0);
    });

    test('Download .diff saves the diff text', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId, seq } = review.agentProposes(requestId, PLAN, DIFF);
        await openRequest(page, requestId);

        const downloading = page.waitForEvent('download');
        await proposal(page, eventId).getByRole('button', { name: 'Download .diff', exact: true }).click();
        const download = await downloading;

        expect(download.suggestedFilename()).toBe(`${requestId}-${seq}.diff`);
        expect(await readFile(await download.path(), 'utf8')).toBe(DIFF);
    });

    test('Approve sends Q15 with the current proposal', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN, DIFF);
        await openRequest(page, requestId);

        await proposal(page, eventId).getByRole('button', { name: 'Approve', exact: true }).click();

        await expect(proposal(page, eventId).locator('[data-testid="proposal-state"]')).toHaveText('Approved');
        await expect(page.locator(DETAIL_THREAD)).toContainText('approved the proposal');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/approve-proposal')).toEqual([{ proposalEventId: eventId }]);
    });

    test('Request changes sends Q16 with the note', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN);
        await openRequest(page, requestId);

        await proposal(page, eventId).getByRole('button', { name: 'Request changes', exact: true }).click();
        await expect(page.getByRole('dialog').getByRole('button', { name: 'Request changes', exact: true }), 'A note is required.').toBeDisabled();
        await submitNoteDialog(page, 'Put the export into InvoiceTotals itself.', 'Request changes');

        await expect(proposal(page, eventId).locator('[data-testid="proposal-state"]')).toHaveText('Changes requested');
        await expect(page.locator(DETAIL_THREAD)).toContainText('Put the export into InvoiceTotals itself.');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/reject-proposal')).toEqual([{ proposalEventId: eventId, note: 'Put the export into InvoiceTotals itself.' }]);
    });

    test('An older proposal shows Superseded', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const older = review.agentProposes(requestId, 'Write the CSV in a controller.');
        const newer = review.agentProposes(requestId, PLAN, DIFF);

        await openRequest(page, requestId);

        await expect(proposal(page, older.eventId).locator('[data-testid="proposal-state"]')).toHaveText('Superseded');
        await expect(proposal(page, newer.eventId).getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    });
});
