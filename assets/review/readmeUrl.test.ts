import { describe, expect, it } from 'vitest';
import { type ReadmeBase, readmeUrl } from './readmeUrl';

const BASE: ReadmeBase = { reviewId: 'R1', dir: 'docs', directories: new Set(['docs/user']) };

describe('ReadmeUrlTest', () => {
    it('Opens a relative file link at the review commit, with its line anchor', () => {
        expect(readmeUrl('guide.md', 'href', BASE)).toBe('/reviews/R1/files/docs/guide.md');
        expect(readmeUrl('../src/Kernel.php#L10', 'href', BASE)).toBe('/reviews/R1/files/src/Kernel.php#L10');
        expect(readmeUrl('/README.md', 'href', BASE)).toBe('/reviews/R1/files/README.md');
    });

    it('Opens a directory link in the directory view', () => {
        expect(readmeUrl('user', 'href', BASE)).toBe('/reviews/R1/tree/docs/user');
        expect(readmeUrl('specs/', 'href', BASE)).toBe('/reviews/R1/tree/docs/specs');
        expect(readmeUrl('..', 'href', BASE)).toBe('/reviews/R1');
    });

    it('Reads a relative image through V8', () => {
        expect(readmeUrl('./img/logo%20big.png', 'src', BASE)).toBe('/api/v1/reviews/R1/raw?path=docs%2Fimg%2Flogo%20big.png');
    });

    it('Keeps absolute URLs and in-page anchors', () => {
        for (const url of ['https://symfony.com', 'mailto:a@b.c', '//cdn.example/x.png', '#installation']) {
            expect(readmeUrl(url, 'href', BASE)).toBe(url);
        }
    });
});
