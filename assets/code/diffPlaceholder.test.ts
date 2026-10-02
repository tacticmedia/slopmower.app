import { describe, expect, it } from 'vitest';
import type { CommitDiffFile } from '../api/types/commit-diff';
import { diffPlaceholder } from './diffPlaceholder';

const RENAMED: CommitDiffFile = {
    status: 'renamed',
    path: 'src/Billing/Totals.php',
    oldPath: 'src/Billing/InvoiceTotals.php',
    oldBlobSha: '3b18e512dba79e4c8300dd08aeb37f8e728b8dad',
    newBlobSha: '3b18e512dba79e4c8300dd08aeb37f8e728b8dad',
    oldMode: '100644',
    newMode: '100644',
    additions: 0,
    deletions: 0,
    binary: false,
    tooLarge: false,
    touchesAnchor: true,
};

describe('DiffPlaceholderTest', () => {
    it('A pure rename shows a placeholder, and a changed blob renders', () => {
        expect(diffPlaceholder(RENAMED)).toBe('The content is unchanged.');
        expect(diffPlaceholder({ ...RENAMED, newBlobSha: '9c2e4a1f0b3d5e7f8091a2b3c4d5e6f708192a3b' })).toBeNull();
    });
});
