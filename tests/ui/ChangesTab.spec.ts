import type { CommitDiff, CommitDiffFile } from '../../assets/api/types/commit-diff';
import { addedFile, commitDiff, commitLink, modifiedFile, sha1 } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE, SMALL_FILE_TEXT } from './support/files';
import { apiError, json, text } from './support/mockApi';
import { openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

/** The small file with `tax` as the tax term on line 11. */
function smallFile(tax: string): string {
    return SMALL_FILE_TEXT.replace('intdiv($net * $taxBasisPoints, 10000)', tax);
}

test.describe('ChangesTab', () => {
    test('The Changes tab shows one FileDiff per linked commit with the anchored file first', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const extract = commitLink(sha1('Extract tax()'), 'Extract tax()');
        const round = commitLink(sha1('Round the tax'), 'Round the tax');
        const [readme, movedReadme, changelog] = ['# Code view\n', '# Code view\n\nTax moved.\n', 'Round the tax.\n'];
        const [extracted, rounded] = [smallFile('$this->tax($net, $taxBasisPoints)'), smallFile('$this->roundedTax($net, $taxBasisPoints)')];
        const blobs = new Map([readme, movedReadme, changelog, SMALL_FILE_TEXT, extracted, rounded].map((content) => [sha1(content), content]));
        // Q9 lists the files in path order, so the UI has to move the anchored file up.
        const changedFiles: [string, CommitDiffFile[]][] = [
            [extract.commitSha, [modifiedFile('README.md', readme, movedReadme, false), modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, extracted, true)]],
            [round.commitSha, [addedFile('CHANGELOG.md', changelog), modifiedFile(SMALL_FILE, extracted, rounded, true)]],
        ];
        review.agentClaims(request.id);
        review.agentLinks(request.id, extract);
        review.agentLinks(request.id, round);
        review.agentMarksReady(request.id, 'Extracted and rounded the tax.');
        const diffs = new Map<string, CommitDiff>(
            changedFiles.map(([commitSha, files]) => [commitSha, commitDiff(review.find(request.id), commitSha === extract.commitSha ? extract : round, files)]),
        );
        api.on('GET', '/api/v1/requests/:requestId/commits/:commitSha', ({ params }) => json('commit-diff.schema.json', diffs.get(params.commitSha ?? '')));
        api.on('GET', '/api/v1/repositories/:repositoryId/blobs/:blobSha', ({ params }) => {
            const blob = blobs.get(params.blobSha ?? '');

            return undefined === blob ? apiError(404, 'not_found', `No blob ${params.blobSha} exists.`) : text(blob);
        });
        await openRequest(page, request.id);

        await page.locator('nav[aria-label="Request"] a').filter({ hasText: 'Changes' }).click();

        await expect(page).toHaveURL(/tab=changes/);
        await expect(page.locator('[data-testid="commit-changes"]')).toHaveCount(2);
        // Q3 breaks a tie of linkedAt by SHA, so the section order is not checked.
        for (const [commitSha, paths] of [
            [extract.commitSha, [SMALL_FILE, 'README.md']],
            [round.commitSha, [SMALL_FILE, 'CHANGELOG.md']],
        ] as const) {
            const fileDiffs = page.locator(`[data-testid="commit-changes"][data-commit-sha="${commitSha}"] [data-testid="commit-diff-file"]`);
            await expect(fileDiffs).toHaveCount(paths.length);
            expect(await fileDiffs.evaluateAll((elements) => elements.map((element) => element.getAttribute('data-path')))).toEqual(paths);
            for (let index = 0; index < paths.length; index++) {
                await expect(fileDiffs.nth(index).locator('diffs-container [data-line-type]').first()).toBeAttached();
            }
        }
    });
});
