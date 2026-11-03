import type { Page } from '@playwright/test';
import type { Request } from '../../assets/api/types/request';
import { FakeReview } from './support/fakeReview';
import { apiError } from './support/mockApi';
import { DETAIL, DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const ACTION_BAR = `${DETAIL} [data-testid="action-bar"]`;

const DECISION = `${DETAIL_THREAD} [data-testid="proposal-decision"]`;

const APPROVE = '/api/v1/requests/:requestId/approve-proposal';

const VERIFY = '/api/v1/requests/:requestId/verify';

function ready(review: FakeReview, body: string): Request {
    const request = review.seedGeneral(body);
    review.agentClaims(request.id);
    review.agentMarksReady(request.id, 'Done.');

    return request;
}

function planned(review: FakeReview): { request: Request; eventId: string } {
    const request = review.seedGeneral('Add a CSV export of the invoice totals.', true);
    review.agentClaims(request.id);

    return { request, eventId: review.agentProposes(request.id, 'Write the CSV in a new class.').eventId };
}

async function chooseNext(page: Page, container: string, label: string): Promise<void> {
    await page.locator(container).getByRole('button', { name: `More ${label.toLowerCase()} options`, exact: true }).click();
    await page.getByRole('menuitem', { name: `${label} and go to the next one`, exact: true }).click();
}

function detailOf(page: Page, requestId: string) {
    return page.locator(`${DETAIL}[data-request-id="${requestId}"]`);
}

test.describe('NextRequest', () => {
    test('Approve and go to the next one sends Q15 and opens the oldest other waiting request', async ({ page, api }) => {
        const review = new FakeReview(api);
        const { request, eventId } = planned(review);
        const oldest = ready(review, 'Extract the tax calculation.');
        ready(review, 'Rename the totals class.');
        await openRequest(page, request.id);

        await chooseNext(page, DECISION, 'Approve');

        await expect(detailOf(page, oldest.id)).toBeVisible();
        expect(api.bodies('POST', APPROVE)).toEqual([{ proposalEventId: eventId }]);
    });

    test('Resolve conversation and Accept with go to the next one send their command and open the next request', async ({ page, api }) => {
        const review = new FakeReview(api);
        const finished = ready(review, 'Extract the tax calculation.');
        const suggested = review.agentSuggests(null, 'Round half cents up', 'Use `PHP_ROUND_HALF_UP`.');
        const asked = review.seedGeneral('Is strict_types needed here?');
        review.agentClaims(asked.id);
        review.agentAsks(asked.id, 'Should the other files declare it too?');
        await openRequest(page, finished.id);

        await chooseNext(page, ACTION_BAR, 'Resolve conversation');
        await expect(detailOf(page, suggested.id)).toBeVisible();
        await chooseNext(page, ACTION_BAR, 'Accept');
        await expect(detailOf(page, asked.id)).toBeVisible();

        expect(api.bodies('POST', VERIFY)).toEqual([{}]);
        expect(api.bodies('POST', '/api/v1/requests/:requestId/accept-suggestion')).toEqual([{ requireApproval: true }]);
    });

    test('With no other request waiting, the item opens the request list with Waiting for you', async ({ page, api }) => {
        const review = new FakeReview(api);
        const finished = ready(review, 'Extract the tax calculation.');
        await openRequest(page, finished.id);

        await chooseNext(page, ACTION_BAR, 'Resolve conversation');

        await expect(page).toHaveURL(`/reviews/${review.id}/requests?awaiting=user`);
        expect(api.bodies('POST', VERIFY)).toEqual([{}]);
    });

    test('A failed command keeps the page and shows the error', async ({ page, api }) => {
        const review = new FakeReview(api);
        const { request } = planned(review);
        ready(review, 'Extract the tax calculation.');
        api.on('POST', APPROVE, () => apiError(422, 'invalid_input', 'Is not the current proposal.', { violations: [{ field: 'proposalEventId', message: 'Is not the current proposal.' }] }));
        await openRequest(page, request.id);

        await chooseNext(page, DECISION, 'Approve');

        await expect(page.locator(`${DECISION} [data-testid="action-error"]`)).toHaveText('Is not the current proposal.');
        await expect(page).toHaveURL(`/requests/${request.id}`);
    });

    test('With an unsaved reply the item asks first: Keep editing sends nothing, and Discard sends and opens the next request', async ({ page, api }) => {
        const review = new FakeReview(api);
        const finished = ready(review, 'Extract the tax calculation.');
        const oldest = ready(review, 'Rename the totals class.');
        await openRequest(page, finished.id);
        const reply = page.getByRole('textbox', { name: 'Reply', exact: true });
        await reply.fill('Keep the old method for one release.');
        const dialog = page.getByRole('alertdialog', { name: 'Discard your unsaved comment?' });

        await chooseNext(page, ACTION_BAR, 'Resolve conversation');
        await dialog.getByRole('button', { name: 'Keep editing', exact: true }).click();
        await expect(dialog).toHaveCount(0);
        await expect(reply).toHaveValue('Keep the old method for one release.');
        expect(api.bodies('POST', VERIFY)).toEqual([]);

        await chooseNext(page, ACTION_BAR, 'Resolve conversation');
        await dialog.getByRole('button', { name: 'Discard', exact: true }).click();
        await expect(detailOf(page, oldest.id)).toBeVisible();
        expect(api.bodies('POST', VERIFY)).toEqual([{}]);
    });

    test('Next request links to the oldest other waiting request, and does not show when none waits', async ({ page, api }) => {
        const review = new FakeReview(api);
        const current = ready(review, 'Extract the tax calculation.');
        const oldest = ready(review, 'Rename the totals class.');
        const newer = ready(review, 'Round half cents up.');
        await openRequest(page, current.id);
        const next = page.locator(ACTION_BAR).getByRole('link', { name: 'Next request', exact: true });

        await expect(next).toHaveAttribute('href', `/requests/${oldest.id}`);

        review.command(oldest.id, 'verify', {});
        review.command(newer.id, 'verify', {});
        await openRequest(page, current.id);
        await expect(next).toHaveCount(0);
    });
});
