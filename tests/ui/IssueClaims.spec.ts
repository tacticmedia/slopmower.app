import { issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { expect, test } from './support/test';

test.describe('Issue claims', () => {
    test('The header names the session that holds the issue, and Release frees it', async ({ page, api }) => {
        new FakeIssues(api).add(issueDetail());
        await page.goto('/issues/BILL-12');
        const claim = page.getByTestId('issue-claim');
        await expect(claim).toContainText('Held by claude-code laptop, session billing-3f9a, until');

        await claim.getByRole('button', { name: 'Release' }).click();

        await expect(claim).toBeHidden();
        expect(api.callsTo('POST', '/api/v1/issues/:issueKey/release')).toHaveLength(1);
    });

    test('The issue list shows the session of a held issue', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.add(issueDetail({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft', claim: null }));
        await page.goto('/projects/BILL');

        await expect(page.getByTestId('issue-row')).toHaveText([/BILL-12 · held by billing-3f9a/, /BILL-13(?! · held)/]);
    });
});
