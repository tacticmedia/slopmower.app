import { issueCommits, issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

test.describe('Issue commits', () => {
    test('The sidebar lists each commit with its SHA, subject, repository, and branch, and links a GitHub commit only', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        const [onDefaultBranch, onBranch] = issueCommits().items;
        if (undefined === onDefaultBranch || undefined === onBranch) {
            throw new Error('issue.commits.json has two commits.');
        }
        issues.commits.set('BILL-12', { items: [onDefaultBranch, { ...onBranch, repository: 'billing', htmlUrl: null }], unavailable: [] });
        await page.goto('/issues/BILL-12');

        const commits = page.getByTestId('issue-commits').getByRole('listitem');

        await expect(commits).toHaveText([
            /^4f1c2a9e8d7bfix: BILL-12 round invoice totals once per invoiceacme\/billing · On the default branch · /,
            /^b7e3d2c1a0f9test: three invoice lines of 0\.335billing · bill-12-round-totals · /,
        ]);
        await expect(commits.first().getByRole('link', { name: '4f1c2a9e8d7b' })).toHaveAttribute('href', 'https://github.com/acme/billing/commit/4f1c2a9e8d7b6c5a4f3e2d1c0b9a8f7e6d5c4b3a');
        await expect(commits.nth(1).getByRole('link')).toHaveCount(0);
    });

    test('An issue without commits says so', async ({ page, api }) => {
        new FakeIssues(api).add(issueDetail());
        await page.goto('/issues/BILL-12');

        await expect(page.getByRole('region', { name: 'Commits' })).toContainText('No commits');
    });

    test('A repository that the server cannot read has a line with its reason', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.commits.set('BILL-12', issueCommits());
        await page.goto('/issues/BILL-12');

        await expect(page.getByTestId('issue-commits-unavailable')).toHaveText('The commits of billing are not listed: it is being cloned.');
    });

    test('A failed list shows its error in the section, and the page stays', async ({ page, api }) => {
        new FakeIssues(api).add(issueDetail());
        api.on('GET', '/api/v1/issues/:issueKey/commits', () => apiError(500, 'internal_error', 'An unexpected error occurred.'));
        await page.goto('/issues/BILL-12');

        await expect(page.getByRole('region', { name: 'Commits' })).toContainText('An unexpected error occurred.');
        await expect(page.getByTestId('issue-thread')).toBeVisible();
    });
});
