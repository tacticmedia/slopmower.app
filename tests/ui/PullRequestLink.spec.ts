import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { DETAIL, DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const PULL_REQUEST = 'https://github.com/acme/billing/pull/42';

test.describe('PullRequestLink', () => {
    test('A linked pull request shows in the header and the thread, and opens in a new tab', async ({ page, context, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentLinksPullRequest(request.id, PULL_REQUEST);
        await context.route(PULL_REQUEST, (route) => route.fulfill({ contentType: 'text/plain', body: 'Pull request 42' }));
        await openRequest(page, request.id);

        const headerLink = page.locator(`${DETAIL} [data-testid="pull-request-list"]`).getByRole('link', { name: PULL_REQUEST, exact: true });
        const entry = page.locator(`${DETAIL_THREAD} [data-testid="thread-comment"]`).filter({ hasText: 'linked a pull request' });
        await expect(entry.getByTestId('thread-pull-request')).toHaveAttribute('href', PULL_REQUEST);
        await expect(entry.getByTestId('thread-pull-request')).toHaveAttribute('target', '_blank');
        await expect(headerLink).toHaveAttribute('rel', 'noreferrer');

        const [tab] = await Promise.all([page.waitForEvent('popup'), headerLink.click()]);

        await expect(tab).toHaveURL(PULL_REQUEST);
    });

    test('The header and the thread show the state of a linked pull request, and a pull_request update reloads it', async ({ page, api, live }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentLinksPullRequest(request.id, PULL_REQUEST);
        review.githubChangesPullRequest(PULL_REQUEST, 'open', 'Extract TaxCalculation');
        await openRequest(page, request.id);

        const header = page.locator(`${DETAIL} [data-testid="pull-request-list"] li`);
        const entry = page.locator(`${DETAIL_THREAD} [data-testid="thread-comment"]`).filter({ hasText: 'linked a pull request' });
        await expect(header.getByTestId('pull-request-state')).toHaveText('Open');
        await expect(header).toContainText('Extract TaxCalculation');
        await expect(entry.getByTestId('pull-request-state')).toHaveText('Open');
        await expect(page.locator('[data-live="connected"]')).toBeAttached();

        review.githubChangesPullRequest(PULL_REQUEST, 'merged', 'Extract TaxCalculation');
        await live.push({ type: 'pull_request', reviewId: review.id, requestIds: [request.id] });

        await expect(header.getByTestId('pull-request-state')).toHaveText('Merged');
        await expect(entry.getByTestId('pull-request-state')).toHaveText('Merged');
    });

    test('An unlinked pull request leaves the header, and the thread shows the unlink', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentLinksPullRequest(request.id, PULL_REQUEST);
        review.agentUnlinksPullRequest(request.id, PULL_REQUEST);
        await openRequest(page, request.id);

        const entry = page.locator(`${DETAIL_THREAD} [data-testid="thread-comment"]`).filter({ hasText: 'unlinked a pull request' });
        await expect(entry.getByTestId('thread-pull-request')).toHaveAttribute('href', PULL_REQUEST);
        await expect(page.locator(`${DETAIL} [data-testid="pull-request-list"]`)).toHaveCount(0);
    });
});
