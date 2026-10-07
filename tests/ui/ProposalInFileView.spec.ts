import type { ProposeSolutionInput } from '../../assets/api/types/inputs';
import { fixture } from './support/builders';
import { openFile, SAVED_THREAD } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { DETAIL_THREAD } from './support/requestScreens';
import { expect, test } from './support/test';

const { plan: PLAN } = fixture<Required<ProposeSolutionInput>>('inputs.propose-solution.json');

const FILE_HEADER_LABEL = '[data-testid="file-header"] [data-testid="plan-to-approve"]';

const CODE_THREAD = `[data-testid="code-view"] ${SAVED_THREAD}`;

const THREAD_HEADER_LABEL = `${CODE_THREAD} header [data-testid="plan-to-approve"]`;

test.describe('ProposalInFileView', () => {
    test('Plan to approve shows in the file header and the thread header until the approval', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        const { eventId } = review.agentProposes(request.id, PLAN);

        await openFile(page, review, SMALL_FILE);

        await expect(page.locator(FILE_HEADER_LABEL)).toHaveText('Plan to approve');
        await expect(page.locator(THREAD_HEADER_LABEL)).toHaveText('Plan to approve');

        await page.locator(CODE_THREAD).getByRole('link', { name: 'Open the proposal', exact: true }).click();
        const proposal = page.locator(`${DETAIL_THREAD} [data-testid="proposal"][data-event-id="${eventId}"]`);
        await proposal.getByRole('button', { name: 'Approve', exact: true }).click();
        await expect(proposal.locator('[data-testid="proposal-state"]')).toHaveText('Approved');
        await openFile(page, review, SMALL_FILE);

        await expect(page.locator(`${CODE_THREAD} [data-testid="proposal-state"]`)).toHaveText('Approved');
        await expect(page.locator(FILE_HEADER_LABEL)).toHaveCount(0);
        await expect(page.locator(THREAD_HEADER_LABEL)).toHaveCount(0);
    });
});
