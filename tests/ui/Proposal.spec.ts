import { readFile } from 'node:fs/promises';
import type { Page } from '@playwright/test';
import type { ProposeSolutionInput } from '../../assets/api/types/inputs';
import { fixture } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { DETAIL_NEXT_STEP, DETAIL_THREAD, openRequest, PLAN_STATE } from './support/requestScreens';
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

const DECISION = `${DETAIL_THREAD} [data-testid="comment-box"] [data-testid="proposal-decision"]`;

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

    test('Approve sends Q15 with the current proposal only, also when the review asks for a pull request', async ({ page, api }) => {
        const review = new FakeReview(api, { review: { delivery: { workspace: null, pullRequest: true } } });
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN, DIFF);
        await openRequest(page, requestId);

        await expect(page.locator(DECISION).getByRole('checkbox')).toHaveCount(0);
        await page.locator(DECISION).getByRole('button', { name: 'Approve', exact: true }).click();

        await expect(proposal(page, eventId).locator('[data-testid="proposal-state"]')).toHaveText('Approved');
        await expect(page.locator(DETAIL_THREAD)).toContainText('approved the proposal');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/approve-proposal')).toEqual([{ proposalEventId: eventId }]);
    });

    test('A reply to a pending plan gives the turn to the agent and keeps the plan pending, and the decision has no Request changes', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN);
        await openRequest(page, requestId);
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveText('The agent proposed a plan. Approve it, or reply to ask for changes.');
        await expect(page.locator(DECISION).getByRole('button', { name: 'Request changes' })).toHaveCount(0);

        await page.locator(`${DETAIL_THREAD} textarea[aria-label="Reply"]`).fill('Put the export into InvoiceTotals itself.');
        await page.locator(`${DETAIL_THREAD} [data-testid="comment-box"]`).getByRole('button', { name: 'Reply', exact: true }).click();

        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveText('Waiting for the agent to answer your reply.');
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveAttribute('data-awaiting', 'agent');
        await expect(proposal(page, eventId).locator('[data-testid="proposal-state"]')).toHaveText('Waiting for approval');
        await expect(page.locator(DECISION).getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
        expect(api.bodies('POST', '/api/v1/requests/:requestId/comments')).toEqual([{ body: 'Put the export into InvoiceTotals itself.' }]);
    });

    test('An older proposal shows Superseded', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const older = review.agentProposes(requestId, 'Write the CSV in a controller.');
        const newer = review.agentProposes(requestId, PLAN, DIFF);

        await openRequest(page, requestId);

        await expect(proposal(page, older.eventId).locator('[data-testid="proposal-state"]')).toHaveText('Superseded');
        await expect(proposal(page, newer.eventId).locator('[data-testid="proposal-state"]')).toHaveText('Waiting for approval');
    });

    test('The decision sits in the reply box, left of Reply, and not in the proposal', async ({ page, api }) => {
        const review = new FakeReview(api);
        const requestId = claimedRequest(review);
        const { eventId } = review.agentProposes(requestId, PLAN);

        await openRequest(page, requestId);

        await expect(proposal(page, eventId).getByRole('button')).toHaveCount(0);
        await expect(proposal(page, eventId).locator('[data-testid="proposal-state"]')).toHaveText('Waiting for approval');
        const box = page.locator(`${DETAIL_THREAD} [data-testid="comment-box"]`);
        const approve = await box.getByRole('button', { name: 'Approve', exact: true }).boundingBox();
        const reply = await box.getByRole('button', { name: 'Reply', exact: true }).boundingBox();
        expect(approve?.x ?? Infinity).toBeLessThan(reply?.x ?? 0);
    });
});
