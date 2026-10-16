import type { ProposeSolutionInput } from '../../assets/api/types/inputs';
import { commitLink, fixture, sha1 } from '../ui/support/builders';
import { FakeReview } from '../ui/support/fakeReview';
import { SMALL_FILE, SYMBOL_FILE } from '../ui/support/files';
import { REQUEST_ROWS } from '../ui/support/requestScreens';
import { expect, test } from './support/test';

const { plan: PLAN, diff: DIFF } = fixture<Required<ProposeSolutionInput>>('inputs.propose-solution.json');

const TOTAL = '#requests-total';

const SUGGESTION_BODY = 'Use `PHP_ROUND_HALF_UP`. `PHP_ROUND_HALF_EVEN` rounds a tax of 12.5 cents to 12 cents, and invoices round a half cent up.';

/** A review with a request in every status, a suggestion, an outdated request, and a plan to approve. */
function reviewWithEveryStatus(review: FakeReview): void {
    review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
    const outdated = review.seedLines(SMALL_FILE, 9, 12, 'Move the rounding of `total()` into its own method.', 'Extract the tax calculation');
    review.markOutdated(outdated.id, sha1('Use percent'));
    const planned = review.seedGeneral('Add a CSV export of the invoice totals.', true, 'CSV export of the invoice totals');
    review.agentClaims(planned.id);
    review.agentProposes(planned.id, PLAN, DIFF);
    const asked = review.seedLines(SYMBOL_FILE, 19, 22, 'Add a test for an amount of zero.');
    review.agentClaims(asked.id);
    review.agentAsks(asked.id, 'Should the test cover a negative amount too?');
    const ready = review.seedLines(SYMBOL_FILE, 9, 9, 'Rename `SCALE`, so that its name says it counts basis points.', 'Name the scale after its unit');
    review.agentClaims(ready.id);
    review.agentLinks(ready.id, commitLink(sha1('Rename SCALE'), 'Rename SCALE to BASIS_POINTS'));
    review.agentMarksReady(ready.id, 'Renamed the constant and its uses.');
    const verified = review.seedGeneral('Wrap long lines in the code view.');
    review.agentClaims(verified.id);
    review.agentMarksReady(verified.id, 'Wrapped.');
    review.command(verified.id, 'verify', {});
    const reopened = review.seedGeneral('Add a changelog entry.');
    review.agentClaims(reopened.id);
    review.agentMarksReady(reopened.id, 'Added.');
    review.command(reopened.id, 'reopen', { note: 'The entry names the wrong version.' });
    const declined = review.seedLines(SMALL_FILE, 1, 1, 'Drop the opening tag.');
    review.command(declined.id, 'wont-do', { reason: 'Every PHP file needs the opening tag.' });
    review.agentSuggests({ kind: 'line', path: SMALL_FILE, range: { startLine: 11, endLine: 11 } }, 'Round half cents up', SUGGESTION_BODY);
}

test.describe('ReviewRequests', () => {
    test('The requests tab lists, filters, and shows the pending drafts', async ({ page, api, captureScreen }) => {
        const review = new FakeReview(api);
        reviewWithEveryStatus(review);

        await page.goto(`/reviews/${review.id}/requests`);
        await expect(page.locator(REQUEST_ROWS)).toHaveCount(review.requests.length);
        await captureScreen('Requests in every status', page.locator('[data-testid="request-list"] table'));

        await page.locator('[data-testid="request-filters"] label').filter({ hasText: /^Waiting for you$/ }).click();
        await expect(page).toHaveURL(/awaiting=user/);
        await captureScreen('Requests waiting for you', page.locator(TOTAL).filter({ hasText: /^[0-9]+ requests$/ }));

        await page.getByRole('search').getByRole('textbox', { name: 'Path prefix' }).fill('docs/');
        await page.getByRole('search').getByRole('button', { name: 'Filter' }).click();
        await captureScreen('No request matches the filters', page.locator(TOTAL).filter({ hasText: 'No request matches these filters.' }));

        const [first] = review.requests;
        review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SYMBOL_FILE, range: { startLine: 11, endLine: 17 } }, body: 'Document the rounding mode.' });
        review.seedDraft({ kind: 'reply', requestId: first?.id ?? '', body: 'Yes, keep it.' });
        review.seedDraft({ kind: 'new_request', anchor: null, title: 'Dark mode screenshot', body: 'Add a dark mode screenshot to the README.' });
        await page.goto(`/reviews/${review.id}/requests`);
        await captureScreen('Pending drafts', page.locator('[data-testid="pending-drafts"] [data-testid="draft"]').last());

        await page.getByRole('button', { name: 'New request', exact: true }).click();
        const newRequest = page.locator('form[aria-label="New request"]');
        await newRequest.getByRole('textbox', { name: 'Title', exact: true }).fill('Keyboard shortcut help');
        await newRequest.getByRole('textbox', { name: 'Description', exact: true }).fill('List the keyboard shortcuts in a dialog that `?` opens.');
        await captureScreen('New request with a title', newRequest);
    });
});
