import type { Page } from '@playwright/test';
import { commitDiff, commitLink, modifiedFile, sha1 } from './support/builders';
import { addSingleComment, CODE_HOST, dragFromPlus, focusedCommentBoxBelow, openFile, shiftClickLines, turnOnWrap, waitForHighlighting, wrapToggle } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles } from './support/files';
import { apiError, json, type MockApi, text } from './support/mockApi';
import { expect, test } from './support/test';

const LONG_LINE_FILE = 'src/Billing/PriceList.php';

const LONG_LINE = `$prices = '${'a'.repeat(400 - "$prices = '';".length)}';`;

/** Line 4 is 400 characters long. */
const LONG_LINE_TEXT = `<?php\n\n$first = 1;\n${LONG_LINE}\n$last = 2;\n`;

const CODE_VIEW = '[data-testid="code-view"]';

const COMMIT_DIFF = '[data-testid="commit-diff-file"]';

function reviewWithLongLine(api: MockApi): FakeReview {
    return new FakeReview(api, { files: { ...codeViewFiles(), [LONG_LINE_FILE]: LONG_LINE_TEXT } });
}

/** The scroll area of the code is inside the shadow root of the view. */
function scrollsSideways(page: Page, container: string): Promise<boolean> {
    return page
        .locator(`${container} ${CODE_HOST} [data-code]`)
        .first()
        .evaluate((code) => code.scrollWidth > code.clientWidth);
}

test.describe('LineWrap', () => {
    test('Wrap lines removes horizontal scrolling', async ({ page, api }) => {
        const review = reviewWithLongLine(api);
        await openFile(page, review, LONG_LINE_FILE);
        await waitForHighlighting(page, 4);
        await expect(wrapToggle(page)).toHaveAttribute('aria-pressed', 'false');
        expect(await scrollsSideways(page, CODE_VIEW)).toBe(true);

        await turnOnWrap(page);

        await expect.poll(() => scrollsSideways(page, CODE_VIEW)).toBe(false);
    });

    test('A wrapped line takes a comment', async ({ page, api }) => {
        const review = reviewWithLongLine(api);
        await openFile(page, review, LONG_LINE_FILE);
        await waitForHighlighting(page, 4);
        await turnOnWrap(page);
        await expect.poll(() => scrollsSideways(page, CODE_VIEW)).toBe(false);

        await shiftClickLines(page, 3, 4);
        await dragFromPlus(page, 4, 4);
        await addSingleComment(page, await focusedCommentBoxBelow(page, 4), 'Split the price list.', '[slot="annotation-4"]');

        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([
            { anchor: { kind: 'range', path: LONG_LINE_FILE, range: { startLine: 3, endLine: 4 } }, body: 'Split the price list.' },
        ]);
    });

    test('The choice applies to the Changes tab and survives a reload', async ({ page, api }) => {
        const review = reviewWithLongLine(api);
        const request = review.seedLines(LONG_LINE_FILE, 4, 4, 'Split the price list.');
        const link = commitLink(sha1('Change the last value'), 'Change the last value');
        const changed = LONG_LINE_TEXT.replace('$last = 2;', '$last = 3;');
        const blobs = new Map([LONG_LINE_TEXT, changed].map((content) => [sha1(content), content]));
        review.agentClaims(request.id);
        review.agentLinks(request.id, link);
        api.on('GET', '/api/v1/requests/:requestId/commits/:commitSha', () =>
            json('commit-diff.schema.json', commitDiff(review.find(request.id), link, [modifiedFile(LONG_LINE_FILE, LONG_LINE_TEXT, changed, true)])),
        );
        api.on('GET', '/api/v1/repositories/:repositoryId/blobs/:blobSha', ({ params }) => {
            const blob = blobs.get(params.blobSha ?? '');

            return undefined === blob ? apiError(404, 'not_found', `No blob ${params.blobSha} exists.`) : text(blob);
        });
        const changesUrl = `/requests/${request.id}?tab=changes`;
        await page.goto(changesUrl);
        await expect.poll(() => scrollsSideways(page, COMMIT_DIFF)).toBe(true);

        await openFile(page, review, LONG_LINE_FILE);
        await turnOnWrap(page);
        await page.goto(changesUrl);

        await expect.poll(() => scrollsSideways(page, COMMIT_DIFF)).toBe(false);
        await page.reload();
        await expect.poll(() => scrollsSideways(page, COMMIT_DIFF)).toBe(false);
    });
});
