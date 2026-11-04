import { commitDiff, commitLink, modifiedFile, sha1 } from './support/builders';
import { CODE_HOST, openFile } from './support/codeView';
import { FakeGitHub } from './support/fakeGitHub';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE, SMALL_FILE, SMALL_FILE_TEXT, SYMBOL_FILE } from './support/files';
import { json } from './support/mockApi';
import { expect, test } from './support/test';

const BLOB_PATH = /^\/repos\/acme\/billing\/git\/blobs\/[0-9a-f]{40}$/;

test.describe('BrowserOnly', () => {
    test('The file view reads the blob from GitHub with the token, and the worker gives the outline', async ({ page, api }) => {
        const review = new FakeReview(api, { browserOnly: true });
        const gitHub = new FakeGitHub(page, (blobSha) => review.blob(blobSha));
        await gitHub.install();

        await openFile(page, review, LARGE_FILE, '#L250');
        await expect(page.locator(`${CODE_HOST} [data-line="250"][data-selected-line]`)).toBeAttached();

        await expect(page.locator('[data-testid="scope-lines"]').getByRole('button')).toHaveText(['2final class UnitOfWork']);
        expect(gitHub.calls).toEqual([{ path: expect.stringMatching(BLOB_PATH), authorization: `Bearer ${FakeGitHub.TOKEN}` }]);
        expect(api.callsTo('GET', '/api/v1/reviews/:reviewId/raw')).toEqual([]);
    });

    test('Go to declaration, symbol anchors, and Raw are absent, and a link opens the file on GitHub', async ({ page, api }) => {
        const review = new FakeReview(api, { browserOnly: true });
        await new FakeGitHub(page, (blobSha) => review.blob(blobSha)).install();

        await openFile(page, review, SYMBOL_FILE);
        const header = page.locator('[data-testid="file-header"]');
        await expect(header.getByRole('link', { name: 'View on GitHub' })).toHaveAttribute('href', `https://github.com/${review.review.repositoryName}/blob/${review.review.commitSha}/${SYMBOL_FILE}`);
        await page.getByRole('button', { name: 'Comment on lines' }).click();
        const lines = page.getByRole('dialog', { name: 'Comment on lines' });
        await lines.getByLabel('Start line').fill('11');
        await lines.getByLabel('End line').fill('17');
        await lines.getByRole('button', { name: 'Comment', exact: true }).click();

        // Lines 11 to 17 are exactly round(), which takes a symbol anchor when the server has the code.
        await expect(page.locator('[slot="annotation-17"] form[data-testid="comment-box"]')).toHaveAttribute('aria-label', 'Comment on lines 11 to 17');
        await expect(header.getByRole('link', { name: 'Raw' })).toHaveCount(0);
        await expect(header.getByLabel('Go to declaration')).toHaveCount(0);
        await expect(header.getByRole('button', { name: 'Symbols' })).toHaveCount(0);
    });

    test('The diff view reads both sides from GitHub', async ({ page, api }) => {
        const review = new FakeReview(api, { browserOnly: true });
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const extracted = SMALL_FILE_TEXT.replace('intdiv($net * $taxBasisPoints, 10000)', '$this->tax($net, $taxBasisPoints)');
        const commit = commitLink(sha1('Extract tax()'), 'Extract tax()');
        const sides = new Map([SMALL_FILE_TEXT, extracted].map((side) => [sha1(side), side]));
        review.agentClaims(request.id);
        review.agentLinks(request.id, commit);
        const file = { ...modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, extracted, true), additions: null, deletions: null, binary: null };
        api.on('GET', '/api/v1/requests/:requestId/commits/:commitSha', () => json('commit-diff.schema.json', commitDiff(review.find(request.id), commit, [file])));
        const gitHub = new FakeGitHub(page, (blobSha) => sides.get(blobSha));
        await gitHub.install();

        await page.goto(`/requests/${request.id}?tab=changes`);

        await expect(page.locator('[data-testid="commit-diff-file"] diffs-container [data-line-type]').first()).toBeAttached();
        expect(gitHub.calls.map(({ path }) => path.slice(path.lastIndexOf('/') + 1)).toSorted()).toEqual([...sides.keys()].toSorted());
    });

    test('The README comes from GitHub when V8 answers code_in_browser', async ({ page, api }) => {
        const review = new FakeReview(api, { browserOnly: true });
        const gitHub = new FakeGitHub(page, (blobSha) => review.blob(blobSha));
        await gitHub.install();

        await page.goto(`/reviews/${review.id}`);

        await expect(page.getByRole('region', { name: 'README.md' }).getByRole('heading', { name: 'Code view' })).toBeVisible();
        expect(gitHub.calls).toHaveLength(1);
    });

    test('A 401 of GitHub asks R11 for the token again and reads the blob with it', async ({ page, api }) => {
        const review = new FakeReview(api, { browserOnly: true });
        const gitHub = new FakeGitHub(page, (blobSha) => review.blob(blobSha));
        await gitHub.install();
        gitHub.revokeToken();

        await openFile(page, review, SMALL_FILE);

        await expect(page.locator(`${CODE_HOST} [data-line="1"]`)).toHaveText('<?php');
        expect(api.callsTo('POST', '/api/v1/repositories/:repositoryId/code-token')).toHaveLength(2);
        expect(gitHub.calls).toHaveLength(2);
    });

    test('A GitHub rate-limit answer shows the rate-limit message', async ({ page, api }) => {
        const review = new FakeReview(api, { browserOnly: true });
        const gitHub = new FakeGitHub(page, (blobSha) => review.blob(blobSha));
        await gitHub.install();
        gitHub.rateLimit();

        await page.goto(review.fileUrl(SMALL_FILE));

        await expect(page.locator('[data-testid="route-error"]')).toContainText('GitHub refused the read for its rate limit. Wait');
    });
});
